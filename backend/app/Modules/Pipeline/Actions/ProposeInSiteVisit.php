<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Add apartment(s) to visit on a project WITHOUT waiting to complete an existing
 * visit — the standalone twin of the "another apartment" step inside visit
 * completion. It plans an in-site next action for the picked units and lets
 * SyncVisitFromNextAction do the rest: assigned → materialize the pending
 * visit(s) for the field agent; unassigned → drop the plan into the dispatch
 * pool for a visits.dispatch holder to hand out (the default).
 *
 * It goes through CreateNextAction so the "exactly one pending plan per subject"
 * invariant holds — closing the prior plan. That is safe for the common case (a
 * project whose in-site plan is already ASSIGNED, so its pending visits stay
 * live — ClosePendingNextActions marks the plan Done, never cancels visits). The
 * one hazard is an UNASSIGNED pooled sibling plan, which the invariant would
 * cancel and thereby drop its units from the board; mergePooledTargets folds
 * those units back into this request first, so adding a unit never loses one.
 */
class ProposeInSiteVisit
{
    public function __construct(
        private CreateNextAction $createNextAction,
        private SyncVisitFromNextAction $syncVisit,
    ) {}

    /**
     * @param  list<int>  $unitIds  the apartment(s) to add to the visit list
     * @return Collection<int, Visit> the visits materialized (empty when pooled)
     */
    public function handle(
        ClientProject $project,
        array $unitIds,
        string $dueDate,
        ?string $dueTime,
        ?int $agentId,
        User $actor,
    ): Collection {
        // Same guard as every other write on a project's pipeline: a closed or
        // frozen project takes no new visits until it is reactivated / unfrozen.
        abort_unless($project->isActive(), 422, 'This project is closed — reactivate it before adding visits.');
        abort_if($project->isFrozen(), 422, 'This project is frozen — unfreeze it before adding visits.');

        $unitIds = $this->mergePooledTargets($project, $unitIds);

        return DB::transaction(function () use ($project, $unitIds, $dueDate, $dueTime, $agentId, $actor) {
            // In-site plans ignore the default assignee (they pool when unassigned),
            // so passing the actor is harmless — it satisfies the signature only.
            $plan = $this->createNextAction->handle($project, null, [
                'type' => NextActionType::InSiteVisit->value,
                'unit_ids' => $unitIds,
                'due_date' => $dueDate,
                'due_time' => $dueTime,
                'assigned_to' => $agentId,
            ], $actor->id, $actor);

            return $this->syncVisit->handle($plan);
        });
    }

    /**
     * Fold an existing UNASSIGNED pooled in-site plan's targeted units into this
     * request, so the "one pending plan" close does not drop them from the pool.
     * A null target means "the whole shortlist" — expanded to its explicit unit
     * ids so the picked apartment(s) join them instead of narrowing the plan.
     *
     * @param  list<int>  $unitIds
     * @return list<int>
     */
    private function mergePooledTargets(ClientProject $project, array $unitIds): array
    {
        $pooled = NextAction::query()->active()->pending()
            ->where('subject_type', $project->getMorphClass())
            ->where('subject_id', $project->id)
            ->where('type', NextActionType::InSiteVisit->value)
            ->whereNull('assigned_to')
            ->first();

        if ($pooled === null) {
            return array_values(array_unique($unitIds));
        }

        $existing = $pooled->target_unit_ids
            ?? $project->shortlistItems()->active()
                ->where('shortlistable_type', 'unit')
                ->whereIn('state', ['shortlisted', 'not_visited'])
                ->pluck('shortlistable_id')->all();

        return array_values(array_unique([...$existing, ...$unitIds]));
    }
}
