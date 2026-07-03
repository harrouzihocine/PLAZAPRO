<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Desire;
use Illuminate\Support\Facades\DB;

/**
 * Flexibility rule: at any point (after a call, an office or in-site visit) the
 * client may change their mind, so a deal can be shifted back to the Desire list.
 * The active deal is archived (reversible) and the client's desire is (re)captured
 * from the form, ready to re-match when new inventory arrives. Blocked, like any
 * archive, once a payment has been recorded (ArchiveClientProject guard).
 */
class ShiftProjectToDesire
{
    public function __construct(
        private ArchiveClientProject $archive,
        private UpsertDesire $upsert,
    ) {}

    /**
     * @param  array<string, mixed>  $desireData
     */
    public function handle(ClientProject $project, array $desireData): Desire
    {
        return DB::transaction(function () use ($project, $desireData) {
            // Stamp BEFORE archiving: the badge that distinguishes "waiting on the
            // desire list" from a plain archive, and what the reopen looks for.
            $project->update(['closed_to_desire_at' => now()]);

            $this->archive->handle($project, 'Shifted to desire');

            return $this->upsert->handle($project->client, $desireData);
        });
    }
}
