<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionType;
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
    public function __construct(private GenerateInSiteVisits $generateInSiteVisits) {}

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

        $eligible = $subject->shortlistItems()->active()
            ->where('shortlistable_type', 'unit')
            ->whereIn('state', ['shortlisted', 'not_visited'])
            ->exists();
        $alreadyOpen = Visit::query()->active()
            ->where('client_project_id', $subject->id)
            ->where('type', 'in_site')
            ->whereNull('completed_at')
            ->exists();
        abort_unless($eligible || $alreadyOpen, 422, $message);

        $created = $this->generateInSiteVisits->handle(
            $subject, (int) $action->assigned_to, $action->due_at, $action->id,
        );

        foreach ($created as $visit) {
            VisitAssigned::dispatch($visit);
        }

        return $created;
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
