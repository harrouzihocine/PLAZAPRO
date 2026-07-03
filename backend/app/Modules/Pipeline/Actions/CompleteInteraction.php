<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Actions\SyncShortlist;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * Complete a visit and record its **required** next action in one transaction.
 * Completing an interaction can never leave the pipeline without a next step — the
 * rule is validated in the FormRequest and re-checked here.
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
        private SyncVisitFromNextAction $syncVisitFromNextAction,
        private AdvanceShortlistFromVisit $advanceShortlist,
        private SyncShortlist $syncShortlist,
    ) {}

    public function handle(Visit $visit, array $data): Visit
    {
        abort_if($visit->isCompleted(), 422, 'This visit is already completed.');
        abort_if(empty($data['next_action']), 422, 'A next action is required to complete a visit.');

        return DB::transaction(function () use ($visit, $data) {
            $visit->update([
                'completed_at' => now(),
                'outcome_id' => $data['outcome_id'] ?? $visit->outcome_id,
                'notes' => $data['notes'] ?? $visit->notes,
                'checklist' => $data['checklist'] ?? $visit->checklist,
            ]);

            $client = Client::findOrFail($visit->client_id);

            // Office visits shortlist the properties the client wants: an optional
            // full re-sync of the deal's list, and — spec rule — at least one
            // property must remain when the visit belongs to a deal. If the client
            // bailed entirely, the deal is archived / shifted to desire instead.
            if ($visit->type === VisitType::Office && $visit->client_project_id !== null) {
                $project = ClientProject::findOrFail($visit->client_project_id);

                if (array_key_exists('shortlist', $data) && $data['shortlist'] !== null) {
                    $this->syncShortlist->handle($project, $data['shortlist'], $visit->id);
                }

                abort_unless(
                    $project->shortlistItems()->active()->exists(),
                    422,
                    'At least one property must remain shortlisted to complete an office visit.',
                );
            }

            $subject = $visit->client_project_id
                ? ClientProject::findOrFail($visit->client_project_id)
                : $client;

            // Default assignee = the client's sales agent, else whoever conducted
            // the visit (only in-site visits force an explicit field-agent assignee).
            $nextAction = $this->createNextAction->handle(
                $subject, $visit, $data['next_action'], $client->assigned_agent_id ?? $visit->agent_id,
            );

            // Completing an in-site visit moves the shortlisted property forward
            // (BEFORE materializing, so a "needs second visit" outcome re-arms the
            // unit for regeneration).
            if ($visit->type === VisitType::InSite) {
                $this->advanceShortlist->handle($visit->fresh()->load('outcome'));
            }

            // A visit-type next step IS the scheduling — materialize the visit(s).
            $this->syncVisitFromNextAction->handle($nextAction);

            return $visit->fresh();
        });
    }
}
