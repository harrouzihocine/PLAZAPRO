<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Actions\AddShortlistItems;
use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Events\InSiteDispatchRequested;
use App\Modules\Pipeline\Events\VisitAssigned;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Support\Collection;

/**
 * Choosing "office visit" / "in-site visit" as the next step IS the scheduling
 * step — this materializes the pending visit(s) from a freshly created next
 * action (called by LogCall, CompleteInteraction and CorrectNextAction; NOT by
 * CreateNextAction, which stays single-purpose on the one-pending invariant).
 *
 *  - call          → nothing to materialize;
 *  - office visit  → reuse-or-create THE one open office visit for the subject
 *                    (agent = the action's assignee, defaulted server-side to the
 *                    client's sales agent; when = the action's due date/time);
 *  - in-site visit → one pending field visit per shortlisted unit, via
 *                    GenerateInSiteVisits — requires a deal with a shortlist, so
 *                    the agent must pick properties before planning field visits.
 *
 * Pending visits are only ever CANCELLED when their plan is superseded
 * (CorrectNextAction) — never when a prior action is closed as done, otherwise
 * completing one in-site visit would kill its parallel siblings.
 */
class SyncVisitFromNextAction
{
    public function __construct(
        private GenerateInSiteVisits $generateInSiteVisits,
        private AddShortlistItems $addShortlistItems,
    ) {}

    /** @return Collection<int, Visit> the visits created (or re-planned) */
    public function handle(NextAction $action): Collection
    {
        return match ($action->type) {
            NextActionType::Call => collect(),
            NextActionType::OfficeVisit => collect([$this->syncOfficeVisit($action)]),
            NextActionType::InSiteVisit => $this->syncInSiteVisits($action),
        };
    }

    private function syncOfficeVisit(NextAction $action): Visit
    {
        [$clientId, $projectId] = $this->resolveSubject($action);

        // One open office visit per subject: re-plan it instead of stacking a
        // second one (also self-heals an orphan left by an older plan).
        $open = Visit::query()->active()
            ->where('client_id', $clientId)
            ->when($projectId !== null, fn ($q) => $q->where('client_project_id', $projectId))
            ->where('type', 'office')
            ->whereNull('completed_at')
            ->first();

        if ($open !== null) {
            $open->update([
                'scheduled_at' => $action->due_at,
                'agent_id' => $action->assigned_to,
                'next_action_id' => $action->id,
            ]);

            return $open;
        }

        $visit = Visit::create([
            'client_id' => $clientId,
            'client_project_id' => $projectId,
            'type' => 'office',
            'agent_id' => $action->assigned_to,
            'next_action_id' => $action->id,
            'scheduled_at' => $action->due_at,
        ]);

        VisitAssigned::dispatch($visit);

        return $visit;
    }

    /** @return Collection<int, Visit> */
    private function syncInSiteVisits(NextAction $action): Collection
    {
        $subject = $action->subject;

        // Field visits go to specific shortlisted units — a bare client (or an
        // empty shortlist) has nothing to visit yet.
        $message = 'Shortlist at least one unit before planning an in-site visit.';
        abort_unless($subject instanceof ClientProject, 422, $message);

        // A targeted plan ("same / another apartment") first makes sure its
        // apartment(s) are on the shortlist and re-armed to visit — so the pool
        // shows them and the generator materialises exactly them.
        $targetUnitIds = $action->target_unit_ids ?: null;
        if ($targetUnitIds !== null) {
            $this->ensureTargetsToVisit($subject, $targetUnitIds);
        }

        $eligible = $subject->shortlistItems()->active()
            ->where('shortlistable_type', 'unit')
            ->whereIn('state', ['shortlisted', 'not_visited'])
            ->when($targetUnitIds !== null, fn ($q) => $q->whereIn('shortlistable_id', $targetUnitIds))
            ->exists();
        $alreadyOpen = Visit::query()->active()
            ->where('client_project_id', $subject->id)
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->when($targetUnitIds !== null, fn ($q) => $q->whereIn('unit_id', $targetUnitIds))
            ->exists();
        abort_unless($eligible || $alreadyOpen, 422, $message);

        // No agent yet: the plan sits in the dispatch pool — the visits are
        // materialized when a visits.dispatch holder assigns it (weekly board).
        if ($action->assigned_to === null) {
            InSiteDispatchRequested::dispatch($action);

            return collect();
        }

        $created = $this->generateInSiteVisits->handle(
            $subject, (int) $action->assigned_to, $action->due_at, $action->id, $targetUnitIds,
        );

        foreach ($created as $visit) {
            VisitAssigned::dispatch($visit);
        }

        return $created;
    }

    /**
     * Make sure each targeted apartment is on the deal's shortlist and in a
     * visitable state: a brand-new "another apartment" is added (shortlisted);
     * an already-visited one chosen for a second look ("same apartment") is
     * re-armed to `not_visited` so the generator regenerates its visit.
     *
     * @param  list<int>  $unitIds
     */
    private function ensureTargetsToVisit(ClientProject $project, array $unitIds): void
    {
        $this->addShortlistItems->handle(
            $project,
            array_map(fn ($id) => ['shortlistable_type' => 'unit', 'shortlistable_id' => $id], $unitIds),
        );

        ShortlistItem::query()->active()
            ->where('client_project_id', $project->id)
            ->where('shortlistable_type', 'unit')
            ->whereIn('shortlistable_id', $unitIds)
            ->whereIn('state', [
                ShortlistState::VisitedInterested->value,
                ShortlistState::VisitedNotInterested->value,
            ])
            ->update(['state' => ShortlistState::NotVisited->value]);
    }

    /** @return array{0: int, 1: int|null} [client_id, client_project_id] */
    private function resolveSubject(NextAction $action): array
    {
        $subject = $action->subject; // Client or ClientProject (morph)

        return $subject instanceof ClientProject
            ? [(int) $subject->client_id, (int) $subject->id]
            : [(int) $subject->getKey(), null];
    }
}
