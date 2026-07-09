<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cancellations & traceability KPIs (catalog §8) — built on the cancel-and-
 * duplicate model: a cancelled sale is a won deal_item whose record status is
 * `cancelled` (history is never mutated). Net absorption subtracts these from
 * the won ones — the true sales number. Dimension filters narrow via the unit.
 */
class BuildCancellationKpis
{
    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $won = $this->wonCount($f, false);
        $cancelled = $this->cancelledCount($f, false);
        $wonLifetime = $this->wonCount($f, true);
        $cancelledLifetime = $this->cancelledCount($f, true);

        $wonValue = $this->wonValue($f);
        $cancelledValue = $this->cancelledValue($f);

        return [
            'rate_period' => KpiMath::pct($cancelled, $won + $cancelled),
            'rate_lifetime' => KpiMath::pct($cancelledLifetime, $wonLifetime + $cancelledLifetime),
            'won' => $won,
            'cancelled' => $cancelled,
            'value_cancelled' => $cancelledValue,
            'net_absorption' => [
                'units' => $won - $cancelled,
                'value' => Money::sub($wonValue, $cancelledValue),
            ],
            'time_to_cancellation_days' => $this->timeToCancellation($f),
            'reasons' => $this->reasons($f),
            'refund_exposure' => $this->refunds($f),
        ];
    }

    /** Won unit items — in the window, or lifetime when $all. */
    private function wonCount(KpiFilters $f, bool $all): int
    {
        return $this->wonBase($f, $all)->count();
    }

    private function wonValue(KpiFilters $f): string
    {
        return (string) $this->wonBase($f, false)->sum('deal_items.agreed_price') ?: '0.00';
    }

    private function wonBase(KpiFilters $f, bool $all): Builder
    {
        $q = DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->join('units', 'units.id', '=', 'deal_items.unit_id')
            ->when(! $all, fn ($qq) => $qq->whereBetween('deal_items.closed_at', [$f->start, $f->end]));

        return $f->applyUnitScope($q);
    }

    private function cancelledCount(KpiFilters $f, bool $all): int
    {
        return $this->cancelledBase($f, $all)->count();
    }

    private function cancelledValue(KpiFilters $f): string
    {
        return (string) $this->cancelledBase($f, false)->sum('deal_items.agreed_price') ?: '0.00';
    }

    private function cancelledBase(KpiFilters $f, bool $all): Builder
    {
        $q = DealItem::query()
            ->where('deal_items.status', 'cancelled')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->join('units', 'units.id', '=', 'deal_items.unit_id')
            ->when(! $all, fn ($qq) => $qq->whereBetween('deal_items.updated_at', [$f->start, $f->end]));

        return $f->applyUnitScope($q);
    }

    /** Average days from won to cancelled (updated_at ≈ cancellation time). */
    private function timeToCancellation(KpiFilters $f): ?int
    {
        $avg = $this->cancelledBase($f, false)
            ->whereNotNull('deal_items.closed_at')
            ->avg(DB::raw('DATEDIFF(deal_items.updated_at, deal_items.closed_at)'));

        return $avg === null ? null : (int) round((float) $avg);
    }

    /**
     * Cancelled sales grouped by their structured reason.
     *
     * @return list<array{reason: string, count: int}>
     */
    private function reasons(KpiFilters $f): array
    {
        return $this->cancelledBase($f, false)
            ->selectRaw("COALESCE(NULLIF(deal_items.cancellation_reason, ''), '—') as reason, COUNT(*) as c")
            ->groupBy('reason')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => ['reason' => (string) $r->reason, 'count' => (int) $r->c])
            ->all();
    }

    /** Refunds paid out inside the window (the refund exposure that landed). */
    private function refunds(KpiFilters $f): string
    {
        $q = Versement::query()->active()
            ->whereNotNull('refunded_at')
            ->whereBetween('refunded_at', [$f->start, $f->end]);

        if ($f->hasLocation() || $f->hasUnitType()) {
            $q->join('units', 'units.id', '=', 'versements.unit_id');
            $f->applyUnitScope($q);
        }

        return (string) $q->sum('versements.amount') ?: '0.00';
    }
}
