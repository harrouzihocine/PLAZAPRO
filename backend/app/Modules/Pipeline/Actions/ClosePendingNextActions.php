<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\NextAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Close every open pending plan for a subject — the "exactly one pending"
 * invariant's other half. Runs when a new plan replaces the old one
 * (CreateNextAction) AND when an interaction is logged without a follow-up
 * (LogCall / CompleteInteraction with no next_action): fulfilling a plan must
 * retire it either way, or it lingers pending forever (stuck CTA, endless
 * reminders, wrong digests).
 *
 * A fulfilled plan is marked Done. An UNDISPATCHED in-site plan (still
 * unassigned in the dispatch pool) was never executed, so it is CANCELLED with
 * a reason instead — recording "replaced before it happened", not a fake
 * completion, and leaving an honest trail on the dispatch history.
 *
 * Done per model (not a bulk update) so each transition is audited. A
 * project-level subject ALSO closes the client-level pending (the qualifying
 * call's plan) — the story keeps ONE pending log to fill.
 */
class ClosePendingNextActions
{
    public function handle(Model $subject, string $replacedReason = 'Replaced before dispatch'): void
    {
        $priorPending = NextAction::query()
            ->active()
            ->pending()
            ->where(function ($q) use ($subject) {
                $q->where(fn ($s) => $s
                    ->where('subject_type', $subject->getMorphClass())
                    ->where('subject_id', $subject->getKey()));

                if ($subject instanceof ClientProject) {
                    $q->orWhere(fn ($s) => $s
                        ->where('subject_type', 'client')
                        ->where('subject_id', $subject->client_id));
                }
            })
            ->get();

        foreach ($priorPending as $prior) {
            if ($prior->type === NextActionType::InSiteVisit && $prior->assigned_to === null) {
                $prior->cancel($replacedReason);

                continue;
            }

            $prior->update([
                'state' => NextActionState::Done->value,
                'completed_at' => now(),
            ]);
        }
    }
}
