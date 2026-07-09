<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\DynamicListItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline & next-action KPIs (catalog §4). The funnel is a cohort: leads
 * (clients) created in the window followed through to a completed visit, a hold,
 * and a won contract — so a sale is always credited to the cohort that produced
 * it. Overdue actions are point-in-time (the health metric of the enforced
 * workflow). Everything is active-scoped.
 */
class BuildPipelineKpis
{
    /** Weight each open stage by its rough probability of closing. */
    private const STAGE_PROBABILITY = [
        'lead' => 0.10,
        'negotiating' => 0.30,
        'deal' => 0.60,
    ];

    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $funnel = $this->funnel($f);

        return [
            'new_leads' => [
                'total' => $funnel['leads'],
                'by_source' => $this->leadsBySource($f),
            ],
            'overdue_actions' => $this->overdueActions($f),
            'funnel' => $funnel,
            'end_to_end' => KpiMath::pct($funnel['contracts'], $funnel['leads']),
            'sales_cycle_days' => $this->salesCycleDays($f),
            'stale_leads' => $this->staleLeads($f),
            'pipeline_value' => $this->pipelineValue($f),
            'lost_reasons' => $this->lostReasons($f),
        ];
    }

    /**
     * Lead → visit → reservation → contract for the window's lead cohort.
     *
     * @return array{leads: int, visits: int, reservations: int, contracts: int, lead_to_visit: float, visit_to_reservation: float, reservation_to_contract: float}
     */
    private function funnel(KpiFilters $f): array
    {
        $cohort = fn () => Client::query()->active()
            ->whereBetween('clients.created_at', [$f->start, $f->end])
            ->when($f->agentId, fn ($q) => $q->where('clients.assigned_agent_id', $f->agentId));

        $leads = $cohort()->count();

        $visits = Visit::query()->active()
            ->whereNotNull('visits.completed_at')
            ->join('clients', 'clients.id', '=', 'visits.client_id')
            ->where('clients.status', 'active')
            ->whereBetween('clients.created_at', [$f->start, $f->end])
            ->when($f->agentId, fn ($q) => $q->where('clients.assigned_agent_id', $f->agentId))
            ->distinct()->count('visits.client_id');

        $reservations = ClientProject::query()->active()
            ->join('clients', 'clients.id', '=', 'client_projects.client_id')
            ->where('clients.status', 'active')
            ->whereBetween('clients.created_at', [$f->start, $f->end])
            ->has('deals') // has any deal (interest committed)
            ->when($f->agentId, fn ($q) => $q->where('clients.assigned_agent_id', $f->agentId))
            ->when($f->locationId, fn ($q) => $q->where('client_projects.location_id', $f->locationId))
            ->distinct()->count('clients.id');

        $contracts = DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->join('client_projects', 'client_projects.id', '=', 'deals.client_project_id')
            ->join('clients', 'clients.id', '=', 'client_projects.client_id')
            ->where('clients.status', 'active')
            ->whereBetween('clients.created_at', [$f->start, $f->end])
            ->when($f->agentId, fn ($q) => $q->where('clients.assigned_agent_id', $f->agentId))
            ->when($f->locationId, fn ($q) => $q->where('client_projects.location_id', $f->locationId))
            ->distinct()->count('clients.id');

        return [
            'leads' => $leads,
            'visits' => $visits,
            'reservations' => $reservations,
            'contracts' => $contracts,
            'lead_to_visit' => KpiMath::pct($visits, $leads),
            'visit_to_reservation' => KpiMath::pct($reservations, $visits),
            'reservation_to_contract' => KpiMath::pct($contracts, $reservations),
        ];
    }

    /**
     * @return list<array{source: string, leads: int}>
     */
    private function leadsBySource(KpiFilters $f): array
    {
        $rows = Client::query()->active()
            ->whereBetween('clients.created_at', [$f->start, $f->end])
            ->when($f->agentId, fn ($q) => $q->where('clients.assigned_agent_id', $f->agentId))
            ->selectRaw('COALESCE(source_id, 0) as sid, COUNT(*) as c')
            ->groupBy('sid')
            ->pluck('c', 'sid');

        $labels = DynamicListItem::whereIn('id', $rows->keys()->filter())->pluck('label', 'id');

        return $rows->map(fn ($c, $sid) => [
            'source' => (int) $sid === 0 ? '—' : (string) ($labels[$sid] ?? '—'),
            'leads' => (int) $c,
        ])->sortByDesc('leads')->values()->all();
    }

    /**
     * Overdue next-actions right now — total + the worst agents.
     *
     * @return array{total: int, by_agent: list<array{agent: string, count: int}>}
     */
    private function overdueActions(KpiFilters $f): array
    {
        $base = fn () => NextAction::query()->active()->overdue()
            ->when($f->agentId, fn ($q) => $q->where('assigned_to', $f->agentId));

        $byAgent = (clone $base())
            ->whereNotNull('assigned_to')
            ->join('users', 'users.id', '=', 'next_actions.assigned_to')
            ->selectRaw('users.name as name, COUNT(*) as c')
            ->groupBy('name')
            ->orderByDesc('c')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['agent' => (string) $r->name, 'count' => (int) $r->c])
            ->all();

        return ['total' => $base()->count(), 'by_agent' => $byAgent];
    }

    /** Median days from client created to first won contract, this window. */
    private function salesCycleDays(KpiFilters $f): ?int
    {
        $days = DealItem::query()->active()
            ->where('deal_items.state', DealState::Won->value)
            ->whereBetween('deal_items.closed_at', [$f->start, $f->end])
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')->where('deals.status', 'active')
            ->join('client_projects', 'client_projects.id', '=', 'deals.client_project_id')
            ->join('clients', 'clients.id', '=', 'client_projects.client_id')
            ->when($f->locationId, fn ($q) => $q->where('client_projects.location_id', $f->locationId))
            ->selectRaw('DATEDIFF(deal_items.closed_at, clients.created_at) as d')
            ->pluck('d')->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 0)->sort()->values();

        if ($days->isEmpty()) {
            return null;
        }
        $mid = intdiv($days->count(), 2);

        return $days->count() % 2 === 1 ? $days[$mid] : (int) round(($days[$mid - 1] + $days[$mid]) / 2);
    }

    /** Active leads with no call/visit and no open project, untouched 30+ days. */
    private function staleLeads(KpiFilters $f, int $days = 30): int
    {
        $cutoff = CarbonImmutable::now()->subDays($days);

        return Client::query()->active()
            ->where('clients.created_at', '<', $cutoff)
            ->when($f->agentId, fn ($q) => $q->where('clients.assigned_agent_id', $f->agentId))
            ->whereDoesntHave('projects', fn ($q) => $q->whereIn('stage', [
                ClientProjectStage::Won->value, ClientProjectStage::Lost->value,
            ]))
            ->whereDoesntHave('calls', fn ($q) => $q->where('called_at', '>=', $cutoff))
            // Client has no `visits` relation — check the visits table directly.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('visits')
                ->whereColumn('visits.client_id', 'clients.id')
                ->where('visits.status', 'active')
                ->where('visits.scheduled_at', '>=', $cutoff))
            ->count();
    }

    /**
     * Open-opportunity value, raw and stage-weighted.
     *
     * @return array{raw: string, weighted: string}
     */
    private function pipelineValue(KpiFilters $f): array
    {
        $rows = ClientProject::query()->active()
            ->whereIn('stage', array_keys(self::STAGE_PROBABILITY))
            ->when($f->locationId, fn ($q) => $q->where('location_id', $f->locationId))
            ->selectRaw('stage, COALESCE(SUM(total_price),0) as v')
            ->groupBy('stage')
            ->pluck('v', 'stage');

        $raw = '0.00';
        $weighted = '0.00';
        foreach ($rows as $stage => $v) {
            $raw = bcadd($raw, (string) $v, 2);
            $weighted = bcadd($weighted, bcmul((string) $v, (string) self::STAGE_PROBABILITY[$stage], 2), 2);
        }

        return ['raw' => $raw, 'weighted' => $weighted];
    }

    /**
     * Lost projects grouped by archive reason within the window (by close time).
     *
     * @return list<array{reason: string, count: int}>
     */
    private function lostReasons(KpiFilters $f): array
    {
        return ClientProject::query()->active()
            ->where('stage', ClientProjectStage::Lost->value)
            ->whereBetween('client_projects.updated_at', [$f->start, $f->end])
            ->when($f->locationId, fn ($q) => $q->where('location_id', $f->locationId))
            ->leftJoin('dynamic_list_items as ar', 'ar.id', '=', 'client_projects.archive_reason_id')
            ->selectRaw("COALESCE(ar.label, '—') as reason, COUNT(*) as c")
            ->groupBy('reason')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => ['reason' => (string) $r->reason, 'count' => (int) $r->c])
            ->all();
    }
}
