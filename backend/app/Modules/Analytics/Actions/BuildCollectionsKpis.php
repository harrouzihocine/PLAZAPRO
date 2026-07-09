<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Collections & receivables KPIs (catalog §5) — the caisse dashboard. Collected
 * is Σ active, non-refunded versements; receivables are the contract value of
 * sold units minus what has been collected; overdue/aging come from the payment
 * schedules. Dimension filters narrow via the versement/schedule unit_id →
 * units (development + room type); the agent dimension is a portfolio concept
 * handled in the agents dashboard, not here.
 */
class BuildCollectionsKpis
{
    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $collected = $this->collected($f, $f->start, $f->end);
        $prevCollected = $this->collected($f, $f->prevStart, $f->prevEnd);

        $contract = $this->contractValue($f);
        $collectedAll = $this->collected($f, CarbonImmutable::createFromTimestamp(0), CarbonImmutable::now());
        $outstanding = Money::sub($contract, $collectedAll);

        $due = $this->dueInPeriod($f);
        $overdue = $this->overdue($f);

        return [
            'collected' => [
                'value' => $collected,
                'previous' => $prevCollected,
                'delta' => KpiMath::delta($collected, $prevCollected),
            ],
            'outstanding' => $outstanding,
            'contract_value' => $contract,
            'pct_contract_collected' => KpiMath::pct($collectedAll, $contract),
            'overdue_amount' => $overdue['amount'],
            'overdue_count' => $overdue['count'],
            'overdue_clients' => $overdue['clients'],
            'collection_rate' => KpiMath::pct($collected, $due), // collected ÷ due in period
            'due_in_period' => $due,
            'aging' => $this->aging($f),
            'avg_days_overdue' => $this->avgDaysOverdue($f),
            'expected_inflow' => $this->expectedInflow($f),
            'plan_compliance' => $this->planCompliance($f),
            'default_risk' => $this->defaultRisk($f),
            'avg_down_payment_pct' => $this->avgDownPaymentPct($f),
            'refunds' => $this->refunds($f),
            'concentration' => $this->concentration($f, $outstanding), // top-client receivables share
        ];
    }

    /** Σ collected (active, non-refunded versements) in a window, filtered. */
    private function collected(KpiFilters $f, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $q = Versement::query()->active()
            ->whereNull('versements.refunded_at')
            ->whereBetween('versements.paid_on', [$start->toDateString(), $end->toDateString()]);

        return (string) $this->scopeByUnit($q, $f)->sum('versements.amount') ?: '0.00';
    }

    /** All-time contract value of sold units + boxes (the receivables base). */
    private function contractValue(KpiFilters $f): string
    {
        $units = DealItem::query()->active()
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->join('units', 'units.id', '=', 'deal_items.unit_id');
        $units = $f->applyUnitScope($units);
        $unitValue = (string) $units->sum('deal_items.agreed_price') ?: '0.00';

        if ($f->hasUnitType()) {
            return $unitValue; // boxes carry no room type
        }

        $boxes = DealItem::query()->active()
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.box_id')
            ->join('boxes', 'boxes.id', '=', 'deal_items.box_id')
            ->when($f->locationId, fn ($q) => $q->where('boxes.location_id', $f->locationId));

        return Money::add($unitValue, (string) $boxes->sum('deal_items.agreed_price') ?: '0.00');
    }

    private function dueInPeriod(KpiFilters $f): string
    {
        $q = PaymentSchedule::query()->active()
            ->whereBetween('due_date', [$f->start->toDateString(), $f->end->toDateString()]);

        return (string) $this->scopeByUnit($q, $f, 'payment_schedules')->sum('amount') ?: '0.00';
    }

    /**
     * Currently-overdue instalments: past due, still owed.
     *
     * @return array{amount: string, count: int, clients: int}
     */
    private function overdue(KpiFilters $f): array
    {
        $q = $this->overdueBase($f);
        $row = (clone $q)->selectRaw('COALESCE(SUM(amount - paid_amount),0) as amount, COUNT(*) as cnt, COUNT(DISTINCT client_project_id) as clients')->first();

        return [
            'amount' => (string) ($row->amount ?? '0.00'),
            'count' => (int) ($row->cnt ?? 0),
            'clients' => (int) ($row->clients ?? 0),
        ];
    }

    private function overdueBase(KpiFilters $f): Builder
    {
        $q = PaymentSchedule::query()->active()
            ->whereColumn('paid_amount', '<', 'amount')
            ->whereDate('due_date', '<', CarbonImmutable::now()->toDateString());

        return $this->scopeByUnit($q, $f, 'payment_schedules');
    }

    /**
     * Receivables aging buckets by days late.
     *
     * @return list<array{bucket: string, amount: string, count: int}>
     */
    private function aging(KpiFilters $f): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $rows = $this->overdueBase($f)
            ->selectRaw("
                CASE
                    WHEN DATEDIFF('$today', due_date) <= 30 THEN '0-30'
                    WHEN DATEDIFF('$today', due_date) <= 60 THEN '31-60'
                    WHEN DATEDIFF('$today', due_date) <= 90 THEN '61-90'
                    ELSE '90+'
                END as bucket,
                COALESCE(SUM(amount - paid_amount),0) as amount,
                COUNT(*) as cnt
            ")
            ->groupBy('bucket')
            ->pluck('cnt', 'bucket');
        $amounts = $this->overdueBase($f)
            ->selectRaw("
                CASE
                    WHEN DATEDIFF('$today', due_date) <= 30 THEN '0-30'
                    WHEN DATEDIFF('$today', due_date) <= 60 THEN '31-60'
                    WHEN DATEDIFF('$today', due_date) <= 90 THEN '61-90'
                    ELSE '90+'
                END as bucket,
                COALESCE(SUM(amount - paid_amount),0) as amount
            ")
            ->groupBy('bucket')
            ->pluck('amount', 'bucket');

        return array_map(fn ($b) => [
            'bucket' => $b,
            'amount' => (string) ($amounts[$b] ?? '0.00'),
            'count' => (int) ($rows[$b] ?? 0),
        ], ['0-30', '31-60', '61-90', '90+']);
    }

    private function avgDaysOverdue(KpiFilters $f): int
    {
        $today = CarbonImmutable::now()->toDateString();
        $avg = $this->overdueBase($f)->avg(DB::raw("DATEDIFF('$today', due_date)"));

        return (int) round((float) $avg);
    }

    /**
     * Expected cash inflow from unpaid, not-yet-overdue instalments.
     *
     * @return array{d30: string, d60: string, d90: string}
     */
    private function expectedInflow(KpiFilters $f): array
    {
        $now = CarbonImmutable::now();
        $inflow = function (int $days) use ($f, $now) {
            $q = PaymentSchedule::query()->active()
                ->whereColumn('paid_amount', '<', 'amount')
                ->whereBetween('due_date', [$now->toDateString(), $now->addDays($days)->toDateString()]);

            return (string) $this->scopeByUnit($q, $f, 'payment_schedules')
                ->selectRaw('COALESCE(SUM(amount - paid_amount),0) as v')->value('v') ?: '0.00';
        };

        return ['d30' => $inflow(30), 'd60' => $inflow(60), 'd90' => $inflow(90)];
    }

    /** Clients whose instalments are all on time ÷ clients with a schedule. */
    private function planCompliance(KpiFilters $f): float
    {
        $withSchedule = (int) $this->scopeByUnit(PaymentSchedule::query()->active(), $f, 'payment_schedules')
            ->distinct()->count('client_project_id');
        if ($withSchedule === 0) {
            return 100.0;
        }
        $late = (int) $this->overdueBase($f)->distinct()->count('client_project_id');

        return KpiMath::pct($withSchedule - $late, $withSchedule);
    }

    /**
     * Clients with ≥2 overdue instalments — the default-risk watchlist.
     *
     * @return array{count: int, clients: list<array{client: string, overdue: int, amount: string}>}
     */
    private function defaultRisk(KpiFilters $f): array
    {
        $rows = $this->overdueBase($f)
            ->join('client_projects', 'client_projects.id', '=', 'payment_schedules.client_project_id')
            ->join('clients', 'clients.id', '=', 'client_projects.client_id')
            ->selectRaw("clients.id as cid, CONCAT(clients.first_name,' ',clients.last_name) as name, COUNT(*) as overdue, COALESCE(SUM(payment_schedules.amount - payment_schedules.paid_amount),0) as amount")
            ->groupBy('cid', 'name')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('amount')
            ->limit(20)
            ->get();

        return [
            'count' => $rows->count(),
            'clients' => $rows->map(fn ($r) => [
                'client' => trim((string) $r->name),
                'overdue' => (int) $r->overdue,
                'amount' => (string) $r->amount,
            ])->all(),
        ];
    }

    /** Average first-payment ÷ contract value across sold units with payments. */
    private function avgDownPaymentPct(KpiFilters $f): float
    {
        $wonUnits = DealItem::query()->active()
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->join('units', 'units.id', '=', 'deal_items.unit_id');
        $wonUnits = $f->applyUnitScope($wonUnits)
            ->where('deal_items.agreed_price', '>', 0)
            ->get(['deal_items.unit_id', 'deal_items.agreed_price']);

        $pcts = [];
        foreach ($wonUnits as $item) {
            $first = Versement::query()->active()->whereNull('refunded_at')
                ->where('unit_id', $item->unit_id)
                ->orderBy('paid_on')->value('amount');
            if ($first !== null) {
                $pcts[] = (float) $first / (float) $item->agreed_price * 100;
            }
        }

        return $pcts === [] ? 0.0 : round(array_sum($pcts) / count($pcts), 1);
    }

    private function refunds(KpiFilters $f): string
    {
        $q = Versement::query()->active()
            ->whereNotNull('refunded_at')
            ->whereBetween('refunded_at', [$f->start, $f->end]);

        return (string) $this->scopeByUnit($q, $f)->sum('versements.amount') ?: '0.00';
    }

    /** Share of total outstanding held by the single largest client (risk). */
    private function concentration(KpiFilters $f, string $outstanding): float
    {
        if (Money::compare($outstanding, '0') <= 0) {
            return 0.0;
        }

        $topClientOverdue = (string) $this->overdueBase($f)
            ->selectRaw('COALESCE(SUM(amount - paid_amount),0) as v')
            ->groupBy('client_project_id')
            ->orderByRaw('v DESC')
            ->value('v') ?: '0.00';

        return KpiMath::pct($topClientOverdue, $outstanding);
    }

    /**
     * Apply the development + room-type dimension filters to a versement /
     * schedule query via its unit_id → units join. Rows without a unit
     * (legacy project-level) are kept only when no unit filter is active.
     */
    private function scopeByUnit(Builder $query, KpiFilters $f, string $table = 'versements'): Builder
    {
        if (! $f->hasLocation() && ! $f->hasUnitType()) {
            return $query;
        }

        return $query
            ->join('units', 'units.id', '=', $table.'.unit_id')
            ->when($f->locationId, fn ($q) => $q->where('units.location_id', $f->locationId))
            ->when($f->unitType, fn ($q) => $q->where('units.room_number_id', $f->unitType));
    }
}
