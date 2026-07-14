<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inventory & stacking-plan KPIs (catalog §2). All figures are point-in-time
 * over active units (the cancel-and-duplicate rule), narrowed by the
 * development + room-type dimensions. "sold" reads the unit's sale_status;
 * velocity reads won deal_items so it lines up with the sales dashboard.
 */
class BuildInventoryKpis
{
    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $status = $this->statusBreakdown($f);
        $total = array_sum($status);
        $sold = $status[SaleStatus::Sold->value] ?? 0;
        // Units parked off the market are not sellable stock — keep them out of
        // the "remaining" pipeline and the sell-through denominator so they neither
        // count as leftover inventory nor dilute the rate. (sold + remaining +
        // unavailable = total, so the tiles still reconcile.)
        $unavailable = $status[SaleStatus::Unavailable->value] ?? 0;
        $offered = $total - $unavailable;
        $remaining = $offered - $sold;
        $velocity = $this->velocity($f);

        return [
            'status_breakdown' => $status,
            'total_units' => $total,
            'sold_units' => $sold,
            'unavailable_units' => $unavailable,
            'remaining_units' => $remaining,
            'sell_through' => KpiMath::pct($sold, $offered),
            'remaining_value' => $this->remainingValue($f),
            'velocity' => $velocity, // units sold / month, rolling 3-mo
            'months_to_sellout' => $velocity > 0 ? round($remaining / $velocity, 1) : null,
            'by_location' => $this->byLocation($f),
            'by_type_demand' => $this->byTypeDemand($f),
            'sold_out_types' => $this->soldOutTypes($f),
            'price_ladder' => $this->priceLadder($f),
            'aging' => $this->aging($f),
            'slow_movers' => $this->slowMovers($f),
        ];
    }

    /** @return array<string, int> sale_status => count */
    private function statusBreakdown(KpiFilters $f): array
    {
        $rows = $this->units($f)
            ->selectRaw('sale_status, COUNT(*) as c')
            ->groupBy('sale_status')
            ->pluck('c', 'sale_status');

        $out = [];
        foreach (SaleStatus::cases() as $case) {
            $out[$case->value] = (int) ($rows[$case->value] ?? 0);
        }

        return $out;
    }

    private function remainingValue(KpiFilters $f): string
    {
        return (string) $this->units($f)
            ->whereIn('sale_status', [SaleStatus::Available->value, SaleStatus::Interested->value])
            ->selectRaw('COALESCE(SUM(COALESCE(price_semi_fini, price_fini)),0) as v')
            ->value('v') ?: '0.00';
    }

    private function velocity(KpiFilters $f): float
    {
        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subMonths(3)->startOfDay();

        $q = DealItem::query()->active()
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->whereBetween('deal_items.closed_at', [$from, $to])
            ->join('units', 'units.id', '=', 'deal_items.unit_id');

        return round($f->applyUnitScope($q)->count() / 3, 1);
    }

    /**
     * @return list<array{location_id: int, location: string, total: int, sold: int, available: int, sell_through: float, remaining_value: string}>
     */
    private function byLocation(KpiFilters $f): array
    {
        return $this->units($f)
            ->join('locations', 'locations.id', '=', 'units.location_id')
            ->selectRaw("
                units.location_id as lid, locations.name as name,
                COUNT(*) as total,
                SUM(CASE WHEN sale_status = 'sold' THEN 1 ELSE 0 END) as sold,
                SUM(CASE WHEN sale_status = 'available' THEN 1 ELSE 0 END) as available,
                COALESCE(SUM(CASE WHEN sale_status IN ('available','interested') THEN COALESCE(price_semi_fini, price_fini) ELSE 0 END),0) as remaining_value
            ")
            ->groupBy('lid', 'name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'location_id' => (int) $r->lid,
                'location' => (string) $r->name,
                'total' => (int) $r->total,
                'sold' => (int) $r->sold,
                'available' => (int) $r->available,
                'sell_through' => KpiMath::pct($r->sold, $r->total),
                'remaining_value' => (string) $r->remaining_value,
            ])
            ->all();
    }

    /**
     * Demand per room type (F2/F3…): how much of each type has sold.
     *
     * @return list<array{type: string, total: int, sold: int, sell_through: float}>
     */
    private function byTypeDemand(KpiFilters $f): array
    {
        return $this->units($f)
            ->leftJoin('dynamic_list_items as rn', 'rn.id', '=', 'units.room_number_id')
            ->selectRaw("
                COALESCE(rn.label, '—') as type,
                COUNT(*) as total,
                SUM(CASE WHEN sale_status = 'sold' THEN 1 ELSE 0 END) as sold
            ")
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'type' => (string) $r->type,
                'total' => (int) $r->total,
                'sold' => (int) $r->sold,
                'sell_through' => KpiMath::pct($r->sold, $r->total),
            ])
            ->all();
    }

    /**
     * Room types fully sold (no stock left) — the real-estate "stockout".
     *
     * @return list<string>
     */
    private function soldOutTypes(KpiFilters $f): array
    {
        return $this->units($f)
            ->leftJoin('dynamic_list_items as rn', 'rn.id', '=', 'units.room_number_id')
            ->selectRaw("COALESCE(rn.label, '—') as type, SUM(CASE WHEN sale_status != 'sold' THEN 1 ELSE 0 END) as remaining, COUNT(*) as total")
            ->groupBy('type')
            ->havingRaw('remaining = 0 AND total > 0')
            ->pluck('type')
            ->all();
    }

    /**
     * Remaining (unsold) stock distributed across price brackets (in Mil, the
     * frontend's unit: 1 Mil = 10 000 DZD). Brackets are chosen for DZD
     * apartment prices; the label is the lower bound in Mil.
     *
     * @return list<array{bracket: string, count: int}>
     */
    private function priceLadder(KpiFilters $f): array
    {
        // Bracket boundaries in base DZD.
        $edges = [0, 5_000_000, 10_000_000, 15_000_000, 20_000_000, 30_000_000];
        $labels = ['<500', '500–1000', '1000–1500', '1500–2000', '2000–3000', '3000+'];

        $prices = $this->units($f)
            ->whereIn('sale_status', [SaleStatus::Available->value, SaleStatus::Interested->value])
            ->selectRaw('COALESCE(price_semi_fini, price_fini) as p')
            ->pluck('p');

        $counts = array_fill(0, count($labels), 0);
        foreach ($prices as $p) {
            $p = (float) $p;
            $idx = count($edges) - 1;
            for ($i = 0; $i < count($edges) - 1; $i++) {
                if ($p < $edges[$i + 1]) {
                    $idx = $i;
                    break;
                }
            }
            $counts[$idx]++;
        }

        $out = [];
        foreach ($labels as $i => $label) {
            $out[] = ['bracket' => $label, 'count' => $counts[$i]];
        }

        return $out;
    }

    /**
     * Oldest available units (days on market ≈ since created — no release_at).
     *
     * @return list<array{reference: string, location: string, days: int}>
     */
    private function aging(KpiFilters $f): array
    {
        return $this->units($f)
            ->where('sale_status', SaleStatus::Available->value)
            ->join('locations', 'locations.id', '=', 'units.location_id')
            ->selectRaw('units.reference, locations.name as location, DATEDIFF(NOW(), units.created_at) as days')
            ->orderByDesc('days')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'reference' => (string) $r->reference,
                'location' => (string) $r->location,
                'days' => (int) $r->days,
            ])
            ->all();
    }

    private function slowMovers(KpiFilters $f, int $days = 90): int
    {
        return $this->units($f)
            ->where('sale_status', SaleStatus::Available->value)
            ->whereRaw('DATEDIFF(NOW(), units.created_at) > ?', [$days])
            ->count();
    }

    /** Base: active units, dimension-filtered. */
    private function units(KpiFilters $f): Builder
    {
        return $f->applyUnitScope(Unit::query()->active());
    }
}
