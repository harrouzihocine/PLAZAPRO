<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientProject;
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
 */
class ArchiveClientProject
{
    public function handle(ClientProject $project, ?string $reason = null): ClientProject
    {
        abort_unless($project->isActive(), 422, 'Only an active deal can be archived.');

        // A deal with recorded payments cannot be archived — refund/remove them first.
        abort_if(
            $project->versements()->active()->exists(),
            422,
            'Archiving is blocked: payments have been recorded on this deal. Refund or remove them first.',
        );

        return DB::transaction(function () use ($project, $reason) {
            $project->paymentSchedules()->active()->get()->each->archive();

            return $project->archive($reason);
        });
    }
}
