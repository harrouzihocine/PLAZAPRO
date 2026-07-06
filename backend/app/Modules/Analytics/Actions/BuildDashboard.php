<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Pipeline\Actions\BuildUpcomingWork;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Build the PERSONAL dashboard for a user. This is a pure read view over the
 * existing domain tables (clients, visits, calls, next_actions, deals) — it
 * never writes.
 *
 * The dashboard is personal for EVERY role: it only ever shows the caller's own
 * book — clients they own, work coming up that involves them, their overdue
 * actions and the rapports they logged this month. The company-wide view lives
 * on a separate page gated by logs.view_all; it is never surfaced here.
 */
class BuildDashboard
{
    public function __construct(private BuildUpcomingWork $upcomingWork) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        // Projects the user contributes to (creator or non-hidden viewer) — the
        // single membership rule shared with BuildUpcomingWork.
        $projectIds = ClientProject::query()
            ->where(fn ($q) => $q
                ->where('created_by', $user->id)
                ->orWhereHas('viewers', fn ($v) => $v
                    ->whereKey($user->id)
                    ->whereNull('client_project_viewers.hidden_at')))
            ->pluck('id');

        $myOverdue = $this->myOverdue($user, $projectIds);

        return [
            'kpis' => [
                // My clients: the ones I own (assigned to me) or captured myself —
                // the same "own book" rule as Client::scopeVisibleTo.
                'clients' => Client::query()->active()
                    ->where(fn ($q) => $q
                        ->where('assigned_agent_id', $user->id)
                        ->orWhere('created_by', $user->id))
                    ->count(),
                // My pipeline: projects I contribute to that are still in play
                // (won is terminal + frozen; lost stays counted — it can reopen).
                'active_projects' => ClientProject::query()->active()
                    ->whereIn('id', $projectIds)
                    ->where('stage', '!=', ClientProjectStage::Won->value)
                    ->count(),
                // My deals still open (not yet closed won / lost).
                'open_deals' => Deal::query()->active()
                    ->where('state', DealState::Open->value)
                    ->where(fn ($q) => $q
                        ->where('created_by', $user->id)
                        ->orWhereIn('client_project_id', $projectIds))
                    ->count(),
                'overdue_actions' => $myOverdue['count'],
            ],
            // This month's own performance, per rapport type + closed deals.
            'month_stats' => $this->monthStats($user),
            // Personal nudge: clients I captured but never worked.
            'my_empty_clients' => Client::query()->active()
                ->where('created_by', $user->id)
                ->doesntHave('projects')
                ->doesntHave('calls')
                ->doesntHave('desire')
                ->count(),
            // What involves ME in the next 7 days, grouped per type — never mixed.
            'my_upcoming' => $this->upcomingWork->handle($user, now()->addDays(7)),
            // The backlog behind the "Overdue actions" KPI — my items only,
            // grouped per type just like my_upcoming (never mixed together).
            'my_overdue' => $myOverdue['groups'],
        ];
    }

    /**
     * My overdue work, grouped per type exactly like BuildUpcomingWork: calls
     * are overdue PENDING call plans; office / in-site visits are sourced from
     * the materialized Visit (not the plan) past their scheduled time and still
     * not completed — the same "visits represent themselves" convention used
     * for upcoming work; tasks are my own past-due open to-dos.
     *
     * @param  Collection<int, int>  $projectIds
     * @return array{count: int, groups: array{calls: array, office_visits: array, in_site_visits: array, tasks: array}}
     */
    private function myOverdue(User $user, Collection $projectIds): array
    {
        $calls = NextAction::query()->active()->overdue()
            ->where('type', NextActionType::Call->value)
            ->where(fn ($q) => $q
                ->where('assigned_to', $user->id)
                ->orWhere(fn ($s) => $s
                    ->where('subject_type', 'client_project')
                    ->whereIn('subject_id', $projectIds)))
            // Morph-aware: project subjects nest the client (one query, not one
            // client per row).
            ->with(['subject' => fn (MorphTo $m) => $m->morphWith([ClientProject::class => ['client:id,first_name,last_name']])])
            ->orderBy('due_at')
            ->get()
            ->map(fn (NextAction $a) => [
                'id' => 'action-'.$a->id,
                'kind' => 'call',
                'due_at' => $a->due_at,
                'is_overdue' => true,
                'client' => $this->clientNameOf($a->subject),
                'link' => $this->linkOf($a->subject),
            ]);

        $visitsOverdue = fn (VisitType $type, string $kind) => Visit::query()->active()
            ->whereNull('completed_at')
            ->where('type', $type->value)
            ->where('scheduled_at', '<', now())
            ->where(fn ($q) => $q
                ->where('agent_id', $user->id)
                ->orWhereIn('client_project_id', $projectIds))
            ->with(['client:id,first_name,last_name', 'unit.location'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Visit $v) => [
                'id' => 'visit-'.$v->id,
                'kind' => $kind,
                'due_at' => $v->scheduled_at,
                'is_overdue' => true,
                'client' => $v->client?->full_name,
                'unit' => $v->unit?->reference,
                'location' => $v->unit?->location?->name,
                'maps_url' => $v->unit?->location?->mapsUrl(),
                'link' => $v->client_project_id
                    ? '/clients/'.$v->client_id.'/projects/'.$v->client_project_id
                    : '/clients/'.$v->client_id,
            ]);

        $tasks = Task::query()->active()->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->where('assigned_to', $user->id)
            ->orderBy('due_at')
            ->get()
            ->map(fn (Task $t) => [
                'id' => 'task-'.$t->id,
                'kind' => 'task',
                'due_at' => $t->due_at,
                'is_overdue' => true,
                'title' => $t->title,
                'link' => '/tasks',
            ]);

        $groups = [
            'calls' => $calls->values()->all(),
            'office_visits' => $visitsOverdue(VisitType::Office, 'office_visit')->values()->all(),
            'in_site_visits' => $visitsOverdue(VisitType::InSite, 'in_site_visit')->values()->all(),
            'tasks' => $tasks->values()->all(),
        ];

        return [
            'count' => array_sum(array_map('count', $groups)),
            'groups' => $groups,
        ];
    }

    /**
     * The caller's own activity this calendar month: rapports logged per type,
     * plus deals they closed won / lost. Attribution is by who did the work
     * (agent on the call/visit, creator on the deal).
     *
     * @return array<string, mixed>
     */
    private function monthStats(User $user): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $visits = fn (VisitType $type) => Visit::query()->active()
            ->where('agent_id', $user->id)
            ->where('type', $type->value)
            ->whereBetween('completed_at', [$start, $end])
            ->count();

        // Distinct projects the user closed won / lost this month (a deal closes
        // per apartment item; closed_at is the exact timestamp).
        $closed = fn (DealState $state) => (int) DealItem::query()->active()
            ->where('deal_items.state', $state->value)
            ->whereBetween('deal_items.closed_at', [$start, $end])
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')
            ->where('deals.created_by', $user->id)
            ->distinct()
            ->count('deals.client_project_id');

        return [
            'label' => $start->translatedFormat('F Y'),
            'calls' => Call::query()->active()
                ->where('agent_id', $user->id)
                ->whereBetween('called_at', [$start, $end])
                ->count(),
            'office_visits' => $visits(VisitType::Office),
            'in_site_visits' => $visits(VisitType::InSite),
            'won' => $closed(DealState::Won),
            'lost' => $closed(DealState::Lost),
        ];
    }

    private function clientNameOf(?object $subject): ?string
    {
        if ($subject instanceof ClientProject) {
            return $subject->client?->full_name;
        }

        return $subject?->full_name ?? null;
    }

    private function linkOf(?object $subject): ?string
    {
        if ($subject instanceof ClientProject) {
            return '/clients/'.$subject->client_id.'/projects/'.$subject->id;
        }

        return $subject !== null ? '/clients/'.$subject->getKey() : null;
    }
}
