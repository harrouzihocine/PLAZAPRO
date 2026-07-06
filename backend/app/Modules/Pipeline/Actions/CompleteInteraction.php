<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Actions\SyncShortlist;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Complete a visit — and, when one is planned, record its next action — in one
 * transaction. The next action is optional: some visits genuinely end a thread
 * (a plan can still be added later via POST /clients/{client}/next-actions).
 *
 * Two automations hang off completion:
 *  - the next step is materialized into its pending visit(s) when it's a visit type
 *    (SyncVisitFromNextAction — office visit, or a field visit per shortlisted unit);
 *  - completing an IN-SITE visit advances the shortlisted property's state from its
 *    outcome (AdvanceShortlistFromVisit), which feeds the Phase-6 closure.
 */
class CompleteInteraction
{
    public function __construct(
        private CreateNextAction $createNextAction,
        private ClosePendingNextActions $closePendingNextActions,
        private SyncVisitFromNextAction $syncVisitFromNextAction,
        private AdvanceShortlistFromVisit $advanceShortlist,
        private SyncShortlist $syncShortlist,
        private ApplyInteractionClosure $applyClosure,
    ) {}

    public function handle(Visit $visit, array $data, User $actor): Visit
    {
        abort_if($visit->isCompleted(), 422, 'This visit is already completed.');
        // A cancelled (superseded / returned-to-pool) visit is a dead row — it
        // must never be completed, or shortlist state advances off a visit that
        // officially never happened.
        abort_if($visit->isCancelled(), 422, 'This visit was cancelled — complete its replacement instead.');
        // An explicitly frozen project takes no activity at all — not even
        // filling its scheduled logs. Unfreeze first (won alone never blocks).
        if ($visit->client_project_id !== null) {
            abort_if(
                ClientProject::findOrFail($visit->client_project_id)->frozen_at !== null,
                422,
                'This project is frozen — unfreeze it before completing the visit.',
            );
        }

        return DB::transaction(function () use ($visit, $data, $actor) {
            $visit->update([
                'completed_at' => now(),
                'outcome_id' => $data['outcome_id'] ?? $visit->outcome_id,
                'notes' => $data['notes'] ?? $visit->notes,
                'checklist' => $data['checklist'] ?? $visit->checklist,
                'objections' => $data['objections'] ?? $visit->objections,
            ]);

            $client = Client::findOrFail($visit->client_id);

            // Interim in-site completion: while the project still has OTHER open
            // in-site visits, this one only records its result and advances its own
            // shortlist item — the thread's conclusion (next action / desire /
            // archive) is decided once, on the LAST remaining visit. The pending
            // plan is deliberately left open so its siblings keep their context.
            // ONE exception: the client may commit to THIS visit's apartment right
            // here — a `deal` closure opens its own deal (one deal per apartment;
            // the siblings stay open and may each conclude into theirs).
            if ($visit->type === VisitType::InSite && $this->hasOpenInSiteSiblings($visit)) {
                $this->advanceShortlist->handle($visit->fresh()->load('outcome'));

                if (($data['closure']['type'] ?? null) === 'deal' && $visit->client_project_id !== null) {
                    $this->applyClosure->handle(
                        ClientProject::findOrFail($visit->client_project_id),
                        $client,
                        $data['closure'],
                        $actor,
                        $visit->id,
                    );
                }

                return $visit->fresh();
            }

            // Office visits shortlist the properties the client wants: an optional
            // full re-sync of the deal's list, and — spec rule — at least one
            // property must remain when the visit belongs to a deal. If the client
            // bailed entirely, the deal is archived / shifted to desire instead.
            if ($visit->type === VisitType::Office && $visit->client_project_id !== null) {
                $project = ClientProject::findOrFail($visit->client_project_id);

                if (array_key_exists('shortlist', $data) && $data['shortlist'] !== null) {
                    $this->syncShortlist->handle($project, $data['shortlist'], $visit->id);
                }

                // …unless the client bailed entirely — an archive / desire closure
                // is exactly how a visit with no remaining interest concludes.
                $bailing = in_array($data['closure']['type'] ?? null, ['archive', 'desire'], true);
                abort_unless(
                    $bailing || $project->shortlistItems()->active()->exists(),
                    422,
                    'At least one property must remain shortlisted to complete an office visit.',
                );
            }

            $subject = $visit->client_project_id
                ? ClientProject::findOrFail($visit->client_project_id)
                : $client;

            // Default assignee = the client's sales agent, else whoever conducted
            // the visit (only in-site visits force an explicit field-agent assignee).
            $nextAction = empty($data['next_action']) ? null : $this->createNextAction->handle(
                $subject, $visit, $data['next_action'], $client->assigned_agent_id ?? $visit->agent_id,
            );

            // No follow-up planned: completing the visit still FULFILS the open
            // plan — close it, or it lingers pending forever.
            if ($nextAction === null) {
                $this->closePendingNextActions->handle($subject);
            }

            // Completing an in-site visit moves the shortlisted property forward
            // (BEFORE materializing, so a "needs second visit" outcome re-arms the
            // unit for regeneration).
            if ($visit->type === VisitType::InSite) {
                $this->advanceShortlist->handle($visit->fresh()->load('outcome'));
            }

            // A visit-type next step IS the scheduling — materialize the visit(s).
            if ($nextAction !== null) {
                $this->syncVisitFromNextAction->handle($nextAction);
            }

            // No next action: the visit MUST resolve into an explicit outcome
            // (desire / archive / deal). Applied LAST, once the visit's own side
            // effects (shortlist advancement) have settled.
            if ($nextAction === null && ! empty($data['closure'])) {
                $projectSubject = $subject instanceof ClientProject ? $subject : null;
                $this->applyClosure->handle(
                    $projectSubject, $client, $data['closure'], $actor, $visit->id,
                );
            }

            return $visit->fresh();
        });
    }

    /**
     * Does the visit's project still have another open (active, uncompleted)
     * in-site visit besides this one? If so, this completion is not the last —
     * the conclusion waits for the final visit.
     */
    private function hasOpenInSiteSiblings(Visit $visit): bool
    {
        if ($visit->client_project_id === null) {
            return false;
        }

        return Visit::query()->active()
            ->where('client_project_id', $visit->client_project_id)
            ->where('type', VisitType::InSite->value)
            ->whereNull('completed_at')
            ->whereKeyNot($visit->getKey())
            ->exists();
    }
}
