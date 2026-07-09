<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Executive headline — the tiles at the top of the command center. Composes
 * the section services (single source of truth for each figure) and cherry-picks
 * the numbers a developer/owner wants at a glance, plus two cheap inline ones
 * (active holds, cancellation rate).
 */
class BuildKpiOverview
{
    public function __construct(
        private BuildSalesKpis $sales,
        private BuildCollectionsKpis $collections,
        private BuildInventoryKpis $inventory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $sales = $this->sales->handle($f);
        $collections = $this->collections->handle($f);
        $inventory = $this->inventory->handle($f);

        return [
            'sales_value' => $sales['sales_value'],
            'units_sold' => $sales['units_sold'],
            'net_sales_value' => $sales['net_sales_value'],
            'collected' => $collections['collected'],
            'outstanding' => $collections['outstanding'],
            'overdue_amount' => $collections['overdue_amount'],
            'sell_through' => $inventory['sell_through'],
            'remaining_value' => $inventory['remaining_value'],
            'active_holds' => $this->activeHolds($f),
            'cancellation_rate' => $this->cancellationRate($f),
            'target' => $sales['target'],
        ];
    }

    /** Live reservation holds right now, dimension-filtered via the unit. */
    private function activeHolds(KpiFilters $f): int
    {
        $q = Reservation::query()->active()
            ->where('reservations.hold_status', HoldStatus::Active->value);

        if ($f->hasLocation() || $f->hasUnitType()) {
            $q->join('units', 'units.id', '=', 'reservations.unit_id');
            $f->applyUnitScope($q);
        }

        return $q->count();
    }

    /** Cancelled sales ÷ (won + cancelled) inside the window — quality signal. */
    private function cancellationRate(KpiFilters $f): float
    {
        $won = $this->wonCount($f);
        $cancelled = $this->cancelledCount($f);
        $total = $won + $cancelled;

        return KpiMath::pct($cancelled, $total);
    }

    private function wonCount(KpiFilters $f): int
    {
        $q = DealItem::query()->active()
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->whereBetween('deal_items.closed_at', [$f->start, $f->end])
            ->join('units', 'units.id', '=', 'deal_items.unit_id');

        return $f->applyUnitScope($q)->count();
    }

    private function cancelledCount(KpiFilters $f): int
    {
        $q = DealItem::query()
            ->where('deal_items.status', 'cancelled')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->whereBetween('deal_items.updated_at', [$f->start, $f->end]);

        if ($f->hasLocation() || $f->hasUnitType()) {
            $q->join('units', 'units.id', '=', 'deal_items.unit_id');
            $f->applyUnitScope($q);
        }

        return $q->count();
    }
}
