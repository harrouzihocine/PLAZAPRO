<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Models\ProjectCost;
use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;

/**
 * Project (development) profitability KPIs (catalog §14) from the optional
 * project_costs table. Per development: gross margin on realized sales, ROI,
 * and break-even coverage (how far collected cash has financed the build).
 * Returns an empty list until costs are entered, so the tab shows a call to
 * action rather than zeros.
 */
class BuildProfitabilityKpis
{
    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $costs = ProjectCost::query()
            ->when($f->locationId, fn ($q) => $q->where('location_id', $f->locationId))
            ->with('location:id,name')
            ->get();

        if ($costs->isEmpty()) {
            return ['configured' => false, 'developments' => [], 'totals' => null];
        }

        $salesByLocation = $this->salesByLocation();
        $collectedByLocation = $this->collectedByLocation();

        $developments = [];
        $totalSales = '0.00';
        $totalCollected = '0.00';
        $totalCost = '0.00';

        foreach ($costs as $cost) {
            $lid = (int) $cost->location_id;
            $sales = (string) ($salesByLocation[$lid] ?? '0.00');
            $collected = (string) ($collectedByLocation[$lid] ?? '0.00');
            $total = $cost->total();

            $totalSales = Money::add($totalSales, $sales);
            $totalCollected = Money::add($totalCollected, $collected);
            $totalCost = Money::add($totalCost, $total);

            $developments[] = [
                'location_id' => $lid,
                'location' => $cost->location?->name ?? '—',
                'cost' => $total,
                'sales_value' => $sales,
                'collected' => $collected,
                'gross_margin' => KpiMath::pct(Money::sub($sales, $total), $sales),
                'roi' => KpiMath::pct(Money::sub($sales, $total), $total),
                'break_even' => KpiMath::pct($collected, $total),
            ];
        }

        return [
            'configured' => true,
            'developments' => $developments,
            'totals' => [
                'cost' => $totalCost,
                'sales_value' => $totalSales,
                'collected' => $totalCollected,
                'gross_margin' => KpiMath::pct(Money::sub($totalSales, $totalCost), $totalSales),
                'roi' => KpiMath::pct(Money::sub($totalSales, $totalCost), $totalCost),
                'break_even' => KpiMath::pct($totalCollected, $totalCost),
            ],
        ];
    }

    /**
     * Lifetime realized sales value per development (units + boxes).
     *
     * @return array<int, string> location_id => value
     */
    private function salesByLocation(): array
    {
        $units = DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)->whereNotNull('deal_items.unit_id')
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->join('units', 'units.id', '=', 'deal_items.unit_id')
            ->selectRaw('units.location_id as lid, COALESCE(SUM(deal_items.agreed_price),0) as v')
            ->groupBy('lid')->pluck('v', 'lid');

        $boxes = DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)->whereNotNull('deal_items.box_id')
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->join('boxes', 'boxes.id', '=', 'deal_items.box_id')
            ->selectRaw('boxes.location_id as lid, COALESCE(SUM(deal_items.agreed_price),0) as v')
            ->groupBy('lid')->pluck('v', 'lid');

        $out = [];
        foreach ($units as $lid => $v) {
            $out[(int) $lid] = (string) $v;
        }
        foreach ($boxes as $lid => $v) {
            $out[(int) $lid] = Money::add($out[(int) $lid] ?? '0.00', (string) $v);
        }

        return $out;
    }

    /**
     * Lifetime collected per development (via the paying unit's location).
     *
     * @return array<int, string> location_id => collected
     */
    private function collectedByLocation(): array
    {
        return Versement::query()->active()->whereNull('versements.refunded_at')
            ->join('units', 'units.id', '=', 'versements.unit_id')
            ->selectRaw('units.location_id as lid, COALESCE(SUM(versements.amount),0) as v')
            ->groupBy('lid')
            ->pluck('v', 'lid')
            ->mapWithKeys(fn ($v, $lid) => [(int) $lid => (string) $v])
            ->all();
    }
}
