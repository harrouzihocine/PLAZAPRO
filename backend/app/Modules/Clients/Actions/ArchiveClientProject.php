<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Actions\ClosePendingNextActions;
use Illuminate\Support\Facades\DB;

/**
 * Archive a deal and everything inside it (its payment plan + recorded
 * versements). Archived rows drop out of the active() scope, so the deal and its
 * money disappear from every normal list until ReactivateClientProject brings
 * them back. Only active children are touched, so a child cancelled beforehand
 * stays cancelled and is not silently revived on reactivate.
 *
 * Reversible by design — no money is resolved or re-derived: the whole plan is
 * hidden as a consistent snapshot and restored intact.
 *
 * `keepPayments` is the escape hatch for losing a deal that already took money
 * (CloseDeal, "archive" resolution): the recorded versements are kept as history
 * instead of blocking the archive — the reason/note explains the refund handling.
 */
class ArchiveClientProject
{
    public function __construct(private ClosePendingNextActions $closePendingNextActions) {}

    public function handle(ClientProject $project, ?string $reason = null, bool $keepPayments = false, ?int $archiveReasonId = null): ClientProject
    {
        abort_unless($project->isActive(), 422, 'Only an active deal can be archived.');

        // An open deal holds Interested inventory — archiving around it would
        // leave the units and boxes stuck "interested" forever. Close the deal first
        // (its own lost→archive path passes here AFTER the deal resolves).
        abort_if(
            $project->activeDeal()->exists(),
            422,
            'A deal is open on this project — close it (won / lost) first.',
        );

        // A deal with recorded payments cannot normally be archived — refund/remove
        // them first — unless the caller explicitly keeps them as history.
        // A refunded versement no longer blocks: its money already went back.
        abort_if(
            ! $keepPayments && $project->versements()->active()->whereNull('refunded_at')->exists(),
            422,
            'Archiving is blocked: payments have been recorded on this deal. Refund or remove them first.',
        );

        return DB::transaction(function () use ($project, $reason, $archiveReasonId) {
            $project->paymentSchedules()->active()->get()->each->archive();

            // Persist the structured lost/archive reason so the Voice-of-Client
            // analytics can aggregate "why deals were lost" cleanly (the label
            // still rides in $reason → cancellation_reason for the audit note).
            // archive() saveQuietly()s the whole model, so setting it here is enough.
            if ($archiveReasonId !== null) {
                $project->archive_reason_id = $archiveReasonId;
            }

            // Nothing may stay "Scheduled" on an archived project: open visits
            // close with it (kept as cancelled history) and the pending plan is
            // resolved — otherwise they linger on the board / oversight forever.
            $project->visits()->active()->whereNull('completed_at')->get()
                ->each(fn ($visit) => $visit->cancel($reason ?? 'Project archived'));
            $this->closePendingNextActions->handle($project, $reason ?? 'Project archived');

            return $project->archive($reason);
        });
    }
}
