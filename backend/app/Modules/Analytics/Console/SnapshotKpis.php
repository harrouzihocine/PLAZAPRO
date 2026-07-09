<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Models\KpiSnapshot;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Materialize the day's KPI values into kpi_snapshots so the command center has
 * real time-series (sales curve, collections curve, receivables + inventory
 * burn-down) without recomputing history live. Flow metrics capture that day's
 * activity (closed_at / paid_on); balance metrics capture the current standing.
 *
 * Idempotent per (date, metric, dimension): re-running a day overwrites its rows.
 * Registered nightly in routes/console.php. --date backfills one specific day.
 */
class SnapshotKpis extends Command
{
    protected $signature = 'kpi:snapshot {--date= : The day to snapshot (Y-m-d), defaults to today}';

    protected $description = 'Materialize daily KPI values into kpi_snapshots for the trend charts';

    public function handle(): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : CarbonImmutable::now();
        $day = $date->toDateString();

        // Flow metrics — that day's activity.
        $this->put($day, 'sales_value', null, $this->dayWonValue($day));
        $this->put($day, 'units_sold', null, (string) $this->dayWonCount($day));
        $this->put($day, 'collected', null, $this->dayCollected($day));

        // Balance metrics — the standing at snapshot time.
        $this->put($day, 'receivables_outstanding', null, $this->outstanding());
        $this->put($day, 'overdue_amount', null, $this->overdueAmount($date));
        $this->put($day, 'active_holds', null, (string) $this->activeHolds());
        $this->put($day, 'available_units', null, (string) $this->availableUnits());
        $this->put($day, 'inventory_value', null, $this->inventoryValue());

        // Per-development breakdown for the headline curves.
        foreach ($this->locationIds() as $lid) {
            $dim = 'location:'.$lid;
            $this->put($day, 'sales_value', $dim, $this->dayWonValue($day, $lid));
            $this->put($day, 'collected', $dim, $this->dayCollected($day, $lid));
            $this->put($day, 'available_units', $dim, (string) $this->availableUnits($lid));
            $this->put($day, 'inventory_value', $dim, $this->inventoryValue($lid));
        }

        $this->info("KPI snapshot written for {$day}.");

        return self::SUCCESS;
    }

    private function put(string $day, string $metric, ?string $dimension, string $value): void
    {
        KpiSnapshot::updateOrCreate(
            ['snapshot_date' => $day, 'metric' => $metric, 'dimension' => $dimension],
            ['value' => $value],
        );
    }

    /** @return list<int> */
    private function locationIds(): array
    {
        return Unit::query()->active()->distinct()->pluck('location_id')->map(fn ($id) => (int) $id)->all();
    }

    private function dayWonValue(string $day, ?int $lid = null): string
    {
        $q = DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)
            ->whereDate('deal_items.closed_at', $day)
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->leftJoin('units', 'units.id', '=', 'deal_items.unit_id')
            ->leftJoin('boxes', 'boxes.id', '=', 'deal_items.box_id')
            ->when($lid, fn ($qq) => $qq->where(fn ($w) => $w
                ->where('units.location_id', $lid)->orWhere('boxes.location_id', $lid)));

        return (string) $q->sum('deal_items.agreed_price') ?: '0.00';
    }

    private function dayWonCount(string $day): int
    {
        return DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->whereDate('deal_items.closed_at', $day)
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->count();
    }

    private function dayCollected(string $day, ?int $lid = null): string
    {
        $q = Versement::query()->active()->whereNull('refunded_at')->whereDate('paid_on', $day);
        if ($lid !== null) {
            $q->join('units', 'units.id', '=', 'versements.unit_id')->where('units.location_id', $lid);
        }

        return (string) $q->sum('versements.amount') ?: '0.00';
    }

    private function outstanding(): string
    {
        $contract = (string) DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->sum('deal_items.agreed_price') ?: '0.00';
        $collected = (string) Versement::query()->active()->whereNull('refunded_at')->sum('amount') ?: '0.00';

        return bcsub($contract, $collected, 2);
    }

    private function overdueAmount(CarbonImmutable $asOf): string
    {
        return (string) PaymentSchedule::query()->active()
            ->whereColumn('paid_amount', '<', 'amount')
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->selectRaw('COALESCE(SUM(amount - paid_amount),0) as v')->value('v') ?: '0.00';
    }

    private function activeHolds(): int
    {
        return Reservation::query()->active()->where('hold_status', HoldStatus::Active->value)->count();
    }

    private function availableUnits(?int $lid = null): int
    {
        return Unit::query()->active()->where('sale_status', SaleStatus::Available->value)
            ->when($lid, fn ($q) => $q->where('location_id', $lid))
            ->count();
    }

    private function inventoryValue(?int $lid = null): string
    {
        return (string) Unit::query()->active()
            ->whereIn('sale_status', [SaleStatus::Available->value, SaleStatus::Interested->value])
            ->when($lid, fn ($q) => $q->where('location_id', $lid))
            ->selectRaw('COALESCE(SUM(COALESCE(price_semi_fini, price_fini)),0) as v')->value('v') ?: '0.00';
    }
}
