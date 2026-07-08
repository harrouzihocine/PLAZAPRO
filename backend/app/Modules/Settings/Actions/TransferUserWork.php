<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Clients\Models\ClientDetailGrant;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Actions\EnsureProjectConversation;
use App\Modules\Collaboration\Actions\GrantFieldAgentChatAccess;
use App\Modules\Collaboration\Actions\RevokeFieldAgentChatAccess;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Events\VisitAssigned;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Offboarding hand-over: move a (leaving) user's whole open book — followed
 * clients, live project seats, pending plans, scheduled visits, open tasks —
 * to one successor, in one transaction. Runs on the open-book queries of
 * BuildUserWorkload, so the wizard's report and what actually moves always
 * agree; run it again to sweep anything created since (a no-op when empty).
 *
 * History is never rewritten: calls, conducted visits, closed deals and
 * recorded payments stay under the leaver's name (their career record), and
 * project created_by keeps saying who really opened each project. The
 * successor takes over the way a colleague would: as the client's follow-up
 * agent and as a project contributor (viewer seat + client-detail grant +
 * chat membership — the ReactivateProjectWithHandoff recipe).
 *
 * In-site plans can go to the successor or back to the dispatch pool
 * ($dispatchToPool) — the pool return mirrors AssignDispatchItem's un-assign:
 * the plan loses its agent, its open visits retire, dispatchers are told.
 *
 * The successor gets ONE summary notification, not one ping per item — so
 * VisitAssigned is handed to the chat-grant listener directly instead of
 * being dispatched (dispatching would also fan out per-visit notifications).
 */
class TransferUserWork
{
    public function __construct(
        private BuildUserWorkload $workload,
        private EnsureProjectConversation $ensureConversation,
        private GrantFieldAgentChatAccess $grantFieldAgentChat,
        private RevokeFieldAgentChatAccess $revokeFieldAgentChat,
    ) {}

    /**
     * @return array{clients: int, projects: int, next_actions: int, pooled_plans: int, visits: int, tasks: int}
     */
    public function handle(User $from, User $to, User $actor, bool $dispatchToPool = false): array
    {
        abort_if($from->is($to), 422, 'The successor must be a different user.');

        $to->loadMissing('role.permissions');
        abort_unless($to->is_active && $to->isActive(), 422, 'The successor must be an active user.');

        $this->guardCapabilities($from, $to, $dispatchToPool);

        $moved = DB::transaction(function () use ($from, $to, $actor, $dispatchToPool) {
            $clients = $this->transferClients($from, $to);
            $projects = $this->transferProjectSeats($from, $to, $actor);
            [$actions, $pooled] = $this->transferNextActions($from, $to, $dispatchToPool);
            $visits = $this->transferVisits($from, $to);
            $tasks = $this->transferTasks($from, $to);

            return [
                'clients' => $clients,
                'projects' => $projects,
                'next_actions' => $actions,
                'pooled_plans' => $pooled,
                'visits' => $visits,
                'tasks' => $tasks,
            ];
        });

        ActivityLog::record('work_transferred', $from, $moved + ['to_user_id' => $to->id]);

        $this->notifySuccessor($from, $to, $moved);
        $this->notifyDispatchers($from, $moved['pooled_plans']);

        return $moved;
    }

    /**
     * The successor must be allowed to DO each kind of work they receive —
     * the same rules the normal assignment paths enforce (CanFollowUpClient,
     * ReactivateProjectWithHandoff, AssignVisit). A domain with nothing to
     * move imposes no requirement, so e.g. a financer's tasks can go to
     * another financer without any agent grants.
     */
    private function guardCapabilities(User $from, User $to, bool $dispatchToPool): void
    {
        $clients = $this->workload->openClientsQuery($from)->count();
        $callPlans = $this->workload->pendingActionsQuery($from)
            ->where('type', NextActionType::Call->value)->count();
        abort_if(
            ($clients > 0 || $callPlans > 0) && ! $to->can('calls.log'),
            422,
            'The successor must be an active sales agent (calls.log) to take over clients and planned calls.',
        );

        abort_if(
            $this->workload->contributorProjectsQuery($from)->exists() && ! $to->can('projects.create'),
            422,
            'The successor must be allowed to hold a client project (projects.create).',
        );

        // Visit-shaped work splits the way the live paths split it: OFFICE
        // rapports need someone who conducts visits (visits.conduct — sales
        // agents; office visits ride plans with no is_agent gate, see
        // SyncVisitFromNextAction), while FIELD work needs a real agent
        // (is_agent — the AssignVisit / dispatch-board rule). Pooled in-site
        // plans (and the visits retired with them) impose nothing.
        $officeWork = $this->workload->pendingActionsQuery($from)
            ->where('type', NextActionType::OfficeVisit->value)->count()
            + $this->visitsHeadedToSuccessor($from, $dispatchToPool)
                ->where('type', VisitType::Office->value)->count();
        abort_if(
            $officeWork > 0 && ! $to->can('visits.conduct'),
            422,
            'The successor must be able to conduct visits (visits.conduct) to take over office visits.',
        );

        $fieldWork = ($dispatchToPool ? 0 : $this->workload->pendingActionsQuery($from)
            ->where('type', NextActionType::InSiteVisit->value)->count())
            + $this->visitsHeadedToSuccessor($from, $dispatchToPool)
                ->where('type', VisitType::InSite->value)->count();
        abort_if(
            $fieldWork > 0 && ! $to->isAgent(),
            422,
            'The successor must be a field agent to take over in-site visits.',
        );
    }

    /**
     * The open visits that will be re-assigned (rather than retired): all of
     * them normally; when pooling, the ones NOT riding a pooled in-site plan.
     */
    private function visitsHeadedToSuccessor(User $from, bool $dispatchToPool): Builder
    {
        $q = $this->workload->openVisitsQuery($from);

        if (! $dispatchToPool) {
            return $q;
        }

        $pooledPlanIds = $this->workload->pendingActionsQuery($from)
            ->where('type', NextActionType::InSiteVisit->value)->pluck('id');

        return $q->where(fn ($w) => $w
            ->whereNull('next_action_id')
            ->orWhereNotIn('next_action_id', $pooledPlanIds));
    }

    /** One-by-one updates on purpose: each hand-over lands in the audit log. */
    private function transferClients(User $from, User $to): int
    {
        $clients = $this->workload->openClientsQuery($from)->get();

        foreach ($clients as $client) {
            $client->update(['assigned_agent_id' => $to->id]);
        }

        return $clients->count();
    }

    /**
     * The successor takes a contributor seat on every live project the leaver
     * worked (viewer + client-detail grant + chat), and the leaver's viewer
     * seats are hidden — they are off the team. Where the leaver is the
     * CREATOR, created_by stays (provenance; hiding a creator is impossible
     * by design) — the account is deactivated, so the lingering membership
     * is inert.
     */
    private function transferProjectSeats(User $from, User $to, User $actor): int
    {
        $projects = $this->workload->contributorProjectsQuery($from)->get();

        foreach ($projects as $project) {
            if ($project->created_by !== $from->id) {
                $project->viewers()->updateExistingPivot($from->id, ['hidden_at' => now()]);
            }

            if ($project->created_by !== $to->id) {
                $project->viewers()->syncWithoutDetaching([$to->id => ['added_by' => $actor->id, 'hidden_at' => null]]);
                $project->viewers()->updateExistingPivot($to->id, ['hidden_at' => null]);
            }

            // They must be able to call the client they now handle — the same
            // grant every project hand-off gives (ReactivateProjectWithHandoff).
            ClientDetailGrant::query()->firstOrCreate(
                ['client_id' => $project->client_id, 'user_id' => $to->id],
                ['granted_by' => $actor->id],
            );

            // Chat membership follows the contributor list.
            $this->ensureConversation->handle($project);
        }

        return $projects->count();
    }

    /**
     * @return array{0: int, 1: int} [moved to successor, returned to the pool]
     */
    private function transferNextActions(User $from, User $to, bool $dispatchToPool): array
    {
        // subject eager-loaded: the pool branch reads it per plan (chat revoke).
        $actions = $this->workload->pendingActionsQuery($from)->with('subject')->get();

        $pooledProjects = collect();
        $pooled = 0;

        foreach ($actions as $action) {
            if ($dispatchToPool && $action->type === NextActionType::InSiteVisit) {
                // Mirror of AssignDispatchItem's return-to-pool move: the plan
                // loses its agent and its open visits retire with it.
                $action->update(['assigned_to' => null]);
                $action->visits()->active()->whereNull('completed_at')->get()
                    ->each->cancel('Returned to the dispatch pool (work transfer)');

                if ($action->subject instanceof ClientProject) {
                    $pooledProjects->push($action->subject);
                }

                $pooled++;

                continue;
            }

            // Due dates travel unchanged — an overdue plan stays overdue on the
            // successor's dashboard; hiding the delay is not the transfer's job.
            $action->update(['assigned_to' => $to->id]);
        }

        // The leaver holds no open in-site visit on these anymore: downgrade
        // their field-agent chat grant to read-only (contributors untouched).
        $this->revokeChatGrants($pooledProjects, $from);

        return [$actions->count() - $pooled, $pooled];
    }

    /**
     * Re-assign every remaining open visit (pool-retired ones are already
     * cancelled). Visits materialized from a plan follow it to the successor —
     * same rule as the dispatch board: a plan and its visits never split.
     */
    private function transferVisits(User $from, User $to): int
    {
        $visits = $this->workload->openVisitsQuery($from)->with('clientProject')->get();

        $inSiteProjects = collect();

        foreach ($visits as $visit) {
            $visit->update(['agent_id' => $to->id]);

            if ($visit->type === VisitType::InSite && $visit->clientProject !== null) {
                // Chat access follows the dispatch. Handed to the listener
                // directly — dispatching VisitAssigned would also send one
                // notification per visit, and the successor gets one summary.
                $this->grantFieldAgentChat->handle(new VisitAssigned($visit->fresh(), isNew: false));
                $inSiteProjects->push($visit->clientProject);
            }
        }

        $this->revokeChatGrants($inSiteProjects, $from);

        return $visits->count();
    }

    private function transferTasks(User $from, User $to): int
    {
        $tasks = $this->workload->openTasksQuery($from)->get();

        foreach ($tasks as $task) {
            $task->update(['assigned_to' => $to->id]);
        }

        return $tasks->count();
    }

    /** @param  Collection<int, ClientProject>  $projects */
    private function revokeChatGrants(Collection $projects, User $from): void
    {
        foreach ($projects->unique('id') as $project) {
            $this->revokeFieldAgentChat->handle($project, $from->id);
        }
    }

    /** @param  array<string, int>  $moved */
    private function notifySuccessor(User $from, User $to, array $moved): void
    {
        $count = array_sum($moved);

        if ($count === 0) {
            return; // everything went to the pool — nothing landed on them
        }

        $to->notify(new DomainNotification(
            kind: 'work_transferred',
            key: 'work_transferred',
            params: ['from' => $from->name, 'count' => $count],
            link: '/dashboard',
            subjectType: User::class,
            subjectId: $from->id,
        ));
    }

    /** Pool returns need a dispatcher to act — tell every visits.dispatch holder. */
    private function notifyDispatchers(User $from, int $pooled): void
    {
        if ($pooled === 0) {
            return;
        }

        // role.permissions eager-loaded so can() reads the loaded collection
        // instead of one query per user (same pattern as the dispatch listener).
        $dispatchers = User::query()
            ->active()
            ->where('is_active', true)
            ->with('role.permissions')
            ->get()
            ->filter(fn (User $u) => $u->can('visits.dispatch'));

        foreach ($dispatchers as $dispatcher) {
            $dispatcher->notify(new DomainNotification(
                kind: 'dispatch_request',
                key: 'plans_pooled',
                params: ['count' => $pooled, 'from' => $from->name],
                link: '/dispatch',
                subjectType: User::class,
                subjectId: $from->id,
            ));
        }
    }
}
