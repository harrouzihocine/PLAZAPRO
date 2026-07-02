<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Clients\Models\ClientProject;
use Illuminate\Support\Facades\DB;

/**
 * Remove (no-delete) a deal and everything inside it. The deal, its payment plan
 * and its recorded versements are all marked cancelled — rows and history kept
 * and audited (LogsActivity), never hard-deleted.
 *
 * Terminal, unlike ArchiveClientProject: recorded payments are cancelled along
 * with the parent (not orphaned, not blocked), so there is nothing to reactivate.
 * Every non-cancelled child is swept, whether the deal was active or archived.
 */
class CancelClientProject
{
    public function handle(ClientProject $project, string $reason): ClientProject
    {
        return DB::transaction(function () use ($project, $reason) {
            $childReason = 'Parent deal removed';
            $cancelled = RecordStatus::Cancelled->value;

            $project->paymentSchedules()->where('status', '!=', $cancelled)->get()->each->cancel($childReason);
            $project->versements()->where('status', '!=', $cancelled)->get()->each->cancel($childReason);

            return $project->cancel($reason);
        });
    }
}
