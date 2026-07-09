<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Payments\Models\Versement;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Agent & team performance KPIs (catalog §6). Sales are credited to the agent
 * who closed the deal (deals.created_by, the same attribution the personal
 * dashboard uses); activities to whoever logged them; collections to the agent
 * who sold the paying unit. One row per working agent + a composite leaderboard
 * score. Dimension filters (development/room type) narrow the unit-based
 * figures; the agent filter narrows to one person.
 */
class BuildAgentKpis
{
    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $sales = $this->salesByAgent($f);
        $calls = $this->countByAgent(Call::query()->active()->whereBetween('called_at', [$f->start, $f->end]), 'agent_id');
        $visits = $this->countByAgent(Visit::query()->active()->whereNotNull('completed_at')->whereBetween('completed_at', [$f->start, $f->end]), 'agent_id');
        $overdue = $this->countByAgent(NextAction::query()->active()->overdue()->whereNotNull('assigned_to'), 'assigned_to');
        $cancelled = $this->cancelledByAgent($f);
        $collections = $this->collectionsByAgent($f);
        $workload = $this->countByAgent(
            ClientProject::query()->active()->whereNotIn('stage', [ClientProjectStage::Won->value, ClientProjectStage::Lost->value]),
            'created_by',
        );

        $ids = collect([$sales->keys(), $calls->keys(), $visits->keys(), $overdue->keys(), $collections->keys(), $workload->keys()])
            ->flatten()->unique()
            ->when($f->hasAgent(), fn ($c) => $c->filter(fn ($id) => (int) $id === $f->agentId))
            ->values();

        $names = User::whereIn('id', $ids)->pluck('name', 'id');

        $rows = $ids->map(function ($id) use ($sales, $calls, $visits, $overdue, $cancelled, $collections, $workload, $names) {
            $salesCount = (int) ($sales[$id]['count'] ?? 0);
            $salesValue = (string) ($sales[$id]['value'] ?? '0.00');
            $activities = (int) ($calls[$id] ?? 0) + (int) ($visits[$id] ?? 0);
            $overdueCount = (int) ($overdue[$id] ?? 0);
            $cancelledCount = (int) ($cancelled[$id] ?? 0);

            return [
                'agent_id' => (int) $id,
                'agent' => (string) ($names[$id] ?? '—'),
                'sales_count' => $salesCount,
                'sales_value' => $salesValue,
                'activities' => $activities,
                'overdue' => $overdueCount,
                'cancellation_rate' => KpiMath::pct($cancelledCount, $salesCount + $cancelledCount),
                'collections' => (string) ($collections[$id] ?? '0.00'),
                'workload' => (int) ($workload[$id] ?? 0),
                // Transparent composite: reward closes & activity, penalise overdue.
                'score' => $salesCount * 100 + $activities * 2 - $overdueCount * 10,
            ];
        })
            ->sortByDesc('score')
            ->values()
            ->all();

        return ['agents' => $rows];
    }

    /**
     * @return Collection<int, array{count: int, value: string}>
     */
    private function salesByAgent(KpiFilters $f): Collection
    {
        $q = DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->whereBetween('deal_items.closed_at', [$f->start, $f->end])
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->join('units', 'units.id', '=', 'deal_items.unit_id');
        $q = $f->applyUnitScope($q);

        return $q->selectRaw('deals.created_by as uid, COUNT(*) as c, COALESCE(SUM(deal_items.agreed_price),0) as v')
            ->whereNotNull('deals.created_by')
            ->groupBy('uid')
            ->get()
            ->keyBy('uid')
            ->map(fn ($r) => ['count' => (int) $r->c, 'value' => (string) $r->v]);
    }

    /**
     * @return Collection<int, int> user id => cancelled won count
     */
    private function cancelledByAgent(KpiFilters $f): Collection
    {
        $q = DealItem::query()
            ->where('deal_items.status', 'cancelled')
            ->where('deal_items.state', DealState::Won->value)
            ->whereNotNull('deal_items.unit_id')
            ->whereBetween('deal_items.updated_at', [$f->start, $f->end])
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')
            ->join('units', 'units.id', '=', 'deal_items.unit_id');
        $q = $f->applyUnitScope($q);

        return $q->selectRaw('deals.created_by as uid, COUNT(*) as c')
            ->whereNotNull('deals.created_by')
            ->groupBy('uid')
            ->pluck('c', 'uid')
            ->map(fn ($c) => (int) $c);
    }

    /**
     * Collections credited to the agent who sold the paying unit.
     *
     * @return Collection<int, string> user id => collected
     */
    private function collectionsByAgent(KpiFilters $f): Collection
    {
        $q = Versement::query()->active()->whereNull('versements.refunded_at')
            ->whereBetween('versements.paid_on', [$f->start->toDateString(), $f->end->toDateString()])
            ->join('units', 'units.id', '=', 'versements.unit_id')
            ->join('deal_items', function ($j) {
                $j->on('deal_items.unit_id', '=', 'versements.unit_id')
                    ->where('deal_items.state', DealState::Won->value)
                    ->where('deal_items.status', 'active');
            })
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active');
        $q = $f->applyUnitScope($q);

        return $q->selectRaw('deals.created_by as uid, COALESCE(SUM(versements.amount),0) as v')
            ->whereNotNull('deals.created_by')
            ->groupBy('uid')
            ->pluck('v', 'uid')
            ->map(fn ($v) => (string) $v);
    }

    /**
     * @return Collection<int, int> agent id => row count
     */
    private function countByAgent(Builder $query, string $column): Collection
    {
        return $query->selectRaw("$column as uid, COUNT(*) as c")
            ->groupBy('uid')
            ->pluck('c', 'uid')
            ->map(fn ($c) => (int) $c);
    }
}
