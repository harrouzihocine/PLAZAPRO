<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Models\SalesTarget;
use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\FinishType;
use App\Modules\Payments\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sales & Revenue KPIs (catalog §1) over the real schema: a "sale" is a won
 * deal_item (per apartment / box) at its agreed_price, closed_at in the window.
 * Everything is active-scoped — cancelled/superseded rows never count as sold
 * (the cancel-and-duplicate rule) except the explicit "net" figure, which
 * subtracts sales cancelled inside the window.
 *
 * Money is summed in SQL (decimal) and returned as decimal strings; the frontend
 * scales them to "Mil" for display. Rates/deltas are presentation floats.
 */
class BuildSalesKpis
{
    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $value = $this->sumValue($f, $f->start, $f->end);
        $prevValue = $this->sumValue($f, $f->prevStart, $f->prevEnd);

        $unitsSold = $this->countUnits($f, $f->start, $f->end);
        $prevUnits = $this->countUnits($f, $f->prevStart, $f->prevEnd);
        $boxesSold = $this->countBoxes($f, $f->start, $f->end);

        $cancelled = $this->cancelledValue($f);
        $totalSold = $unitsSold + $boxesSold;

        return [
            'sales_value' => [
                'value' => $value,
                'previous' => $prevValue,
                'delta' => KpiMath::delta($value, $prevValue),
            ],
            'net_sales_value' => [
                'value' => Money::sub($value, $cancelled),
                'cancelled' => $cancelled,
            ],
            'units_sold' => [
                'total' => $unitsSold,
                'boxes' => $boxesSold,
                'previous' => $prevUnits,
                'delta' => KpiMath::delta($unitsSold, $prevUnits),
            ],
            'avg_selling_price' => $totalSold > 0 ? Money::div($value, (string) $totalSold) : '0.00',
            'avg_price_sqm' => $this->avgPriceSqm($f),
            'by_type' => $this->byType($f),
            'revenue_by_location' => $this->revenueByLocation($f),
            'revenue_mix' => [
                'units' => $this->sumUnitValue($f, $f->start, $f->end),
                'boxes' => $this->sumBoxValue($f, $f->start, $f->end),
            ],
            'sales_velocity' => $this->velocity($f),      // units / month, rolling 3-mo
            'discount_rate' => $this->discountRate($f),   // %
            'deal_momentum_days' => $this->dealMomentum($f), // median days deal-open → won
            'target' => $this->target($f, $value, $unitsSold),
        ];
    }

    /** Won unit items (apartments/locals) in a window, dimension-filtered. */
    private function wonUnitItems(KpiFilters $f, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $q = DealItem::query()->active()
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')
            ->where('deals.status', 'active')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->whereBetween('deal_items.closed_at', [$start, $end])
            ->join('units', 'units.id', '=', 'deal_items.unit_id')
            ->when($f->agentId, fn ($qq) => $qq->where('deals.created_by', $f->agentId));

        return $f->applyUnitScope($q);
    }

    /**
     * Won box items in a window. Boxes carry no room type, so a unit_type filter
     * excludes them entirely; a location filter narrows via boxes.location_id.
     */
    private function wonBoxItems(KpiFilters $f, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $q = DealItem::query()->active()
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')
            ->where('deals.status', 'active')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.box_id')
            ->whereBetween('deal_items.closed_at', [$start, $end])
            ->join('boxes', 'boxes.id', '=', 'deal_items.box_id')
            ->when($f->agentId, fn ($qq) => $qq->where('deals.created_by', $f->agentId))
            ->when($f->locationId, fn ($qq) => $qq->where('boxes.location_id', $f->locationId));

        if ($f->hasUnitType()) {
            $q->whereRaw('1 = 0'); // a room-type filter has no boxes
        }

        return $q;
    }

    private function sumUnitValue(KpiFilters $f, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return (string) $this->wonUnitItems($f, $start, $end)
            ->sum('deal_items.agreed_price') ?: '0.00';
    }

    private function sumBoxValue(KpiFilters $f, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return (string) $this->wonBoxItems($f, $start, $end)
            ->sum('deal_items.agreed_price') ?: '0.00';
    }

    private function sumValue(KpiFilters $f, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return Money::add(
            $this->sumUnitValue($f, $start, $end),
            $this->sumBoxValue($f, $start, $end),
        );
    }

    private function countUnits(KpiFilters $f, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $this->wonUnitItems($f, $start, $end)->count();
    }

    private function countBoxes(KpiFilters $f, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $this->wonBoxItems($f, $start, $end)->count();
    }

    /** Value of sales cancelled INSIDE the window (net = gross − this). */
    private function cancelledValue(KpiFilters $f): string
    {
        // A won item later voided: state still 'won', record cancelled. Best
        // available cancellation time is updated_at. Must carry the same agent
        // scope as the gross side, or net = agent gross − company-wide cancels.
        $q = DealItem::query()
            ->where('deal_items.status', 'cancelled')
            ->where('deal_items.state', DealState::Won->value)
            ->whereBetween('deal_items.updated_at', [$f->start, $f->end])
            ->when($f->agentId, fn ($qq) => $qq
                ->join('deals', 'deals.id', '=', 'deal_items.deal_id')
                ->where('deals.created_by', $f->agentId));

        if ($f->hasLocation() || $f->hasUnitType()) {
            $q->join('units', 'units.id', '=', 'deal_items.unit_id');
            $f->applyUnitScope($q);
        }

        return (string) $q->sum('deal_items.agreed_price') ?: '0.00';
    }

    private function avgPriceSqm(KpiFilters $f): ?string
    {
        $row = $this->wonUnitItems($f, $f->start, $f->end)
            ->whereNotNull('units.area_sqm')
            ->where('units.area_sqm', '>', 0)
            ->selectRaw('COALESCE(SUM(deal_items.agreed_price),0) as value, COALESCE(SUM(units.area_sqm),0) as area')
            ->first();

        if (! $row || (float) $row->area <= 0) {
            return null;
        }

        return Money::div((string) $row->value, (string) $row->area);
    }

    /**
     * Units sold + value grouped by room type (F2/F3…), plus a box bucket.
     *
     * @return list<array{type: string, units: int, value: string}>
     */
    private function byType(KpiFilters $f): array
    {
        $rows = $this->wonUnitItems($f, $f->start, $f->end)
            ->leftJoin('dynamic_list_items as rn', 'rn.id', '=', 'units.room_number_id')
            ->selectRaw("COALESCE(rn.label, '—') as type, COUNT(*) as units, COALESCE(SUM(deal_items.agreed_price),0) as value")
            ->groupBy('type')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($r) => [
                'type' => (string) $r->type,
                'units' => (int) $r->units,
                'value' => (string) $r->value,
            ])
            ->all();

        $boxes = $this->countBoxes($f, $f->start, $f->end);
        if ($boxes > 0) {
            $rows[] = [
                'type' => 'box',
                'units' => $boxes,
                'value' => $this->sumBoxValue($f, $f->start, $f->end),
            ];
        }

        return $rows;
    }

    /**
     * Revenue grouped by development (location) — units by units.location_id and
     * boxes by boxes.location_id, merged.
     *
     * @return list<array{location_id: int, location: string, value: string, units: int}>
     */
    private function revenueByLocation(KpiFilters $f): array
    {
        $units = $this->wonUnitItems($f, $f->start, $f->end)
            ->join('locations', 'locations.id', '=', 'units.location_id')
            ->selectRaw('units.location_id as lid, locations.name as name, COUNT(*) as units, COALESCE(SUM(deal_items.agreed_price),0) as value')
            ->groupBy('lid', 'name')
            ->get();

        $boxes = $this->wonBoxItems($f, $f->start, $f->end)
            ->join('locations', 'locations.id', '=', 'boxes.location_id')
            ->selectRaw('boxes.location_id as lid, locations.name as name, COUNT(*) as units, COALESCE(SUM(deal_items.agreed_price),0) as value')
            ->groupBy('lid', 'name')
            ->get();

        $merged = [];
        foreach ($units->concat($boxes) as $r) {
            $lid = (int) $r->lid;
            $merged[$lid] ??= ['location_id' => $lid, 'location' => (string) $r->name, 'value' => '0.00', 'units' => 0];
            $merged[$lid]['value'] = Money::add($merged[$lid]['value'], (string) $r->value);
            $merged[$lid]['units'] += (int) $r->units;
        }

        usort($merged, fn ($a, $b) => Money::compare($b['value'], $a['value']));

        return array_values($merged);
    }

    /** Units sold per month, rolling 3 months (velocity), dimension-filtered. */
    private function velocity(KpiFilters $f): float
    {
        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subMonths(3)->startOfDay();

        return round($this->countUnits($f, $from, $to) / 3, 1);
    }

    /** Average discount off list on won apartments (list = the committed finish). */
    private function discountRate(KpiFilters $f): float
    {
        $rows = $this->wonUnitItems($f, $f->start, $f->end)
            ->select('deal_items.agreed_price', 'deal_items.finish_type', 'units.price_semi_fini', 'units.price_fini')
            ->get();

        $rates = [];
        foreach ($rows as $r) {
            $list = $this->listPrice($r);
            $sold = (float) $r->agreed_price;
            if ($list !== null && $list > 0 && $sold > 0) {
                $rates[] = ($list - $sold) / $list * 100;
            }
        }

        return $rates === [] ? 0.0 : round(array_sum($rates) / count($rates), 1);
    }

    private function listPrice(object $row): ?float
    {
        $finish = $row->finish_type instanceof FinishType
            ? $row->finish_type
            : ($row->finish_type ? FinishType::tryFrom((string) $row->finish_type) : null);

        $price = match ($finish) {
            FinishType::Fini => $row->price_fini,
            FinishType::SemiFini => $row->price_semi_fini,
            default => $row->price_semi_fini ?? $row->price_fini,
        };

        return $price !== null ? (float) $price : null;
    }

    /** Median days from deal opened to won — how fast committed deals close. */
    private function dealMomentum(KpiFilters $f): ?int
    {
        $days = $this->wonUnitItems($f, $f->start, $f->end)
            ->selectRaw('DATEDIFF(deal_items.closed_at, deals.created_at) as d')
            ->pluck('d')
            ->map(fn ($d) => (int) $d)
            ->filter(fn ($d) => $d >= 0)
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return null;
        }

        $mid = intdiv($days->count(), 2);

        return $days->count() % 2 === 1
            ? $days[$mid]
            : (int) round(($days[$mid - 1] + $days[$mid]) / 2);
    }

    /**
     * Target attainment for the window: match a sales_target on the resolved
     * scope (agent > location > company) whose period aligns with the selection.
     *
     * @return array{metric: string, amount: string, actual: string, attainment: float}|null
     */
    private function target(KpiFilters $f, string $value, int $unitsSold): ?array
    {
        if (! in_array($f->period, ['month', 'quarter', 'year'], true)) {
            return null; // targets align to calendar presets only
        }

        [$scope, $scopeId, $scopeKey] = match (true) {
            $f->hasAgent() => ['agent', $f->agentId, null],
            $f->hasLocation() => ['location', $f->locationId, null],
            $f->hasUnitType() => ['unit_type', null, (string) $f->unitType],
            default => ['company', null, null],
        };

        $target = SalesTarget::query()
            ->where('metric', 'sales_value')
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->where('scope_key', $scopeKey)
            ->where('period_type', $f->period)
            ->whereDate('period_start', $f->start->toDateString())
            ->first();

        if (! $target) {
            return null;
        }

        return [
            'metric' => 'sales_value',
            'amount' => (string) $target->target_amount,
            'actual' => $value,
            'attainment' => KpiMath::pct($value, (string) $target->target_amount),
        ];
    }
}
