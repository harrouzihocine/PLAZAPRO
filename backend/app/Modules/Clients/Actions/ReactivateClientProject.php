<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientProject;
use Illuminate\Support\Facades\DB;

/**
 * The exact reverse of ArchiveClientProject: bring an archived deal and the
 * children that were archived with it back to active. Only rows currently in the
 * `archived` state are revived — children cancelled independently before the
 * archive stay cancelled.
 */
class ReactivateClientProject
{
    public function handle(ClientProject $project): ClientProject
    {
        abort_unless($project->isArchived(), 422, 'Only an archived deal can be reactivated.');

        return DB::transaction(function () use ($project) {
            $project->paymentSchedules()->archived()->get()->each->reactivate();
            $project->versements()->archived()->get()->each->reactivate();

            // No longer waiting on the desire list once it's back in play.
            if ($project->closed_to_desire_at !== null) {
                $project->update(['closed_to_desire_at' => null]);
            }

            return $project->reactivate();
        });
    }
}
