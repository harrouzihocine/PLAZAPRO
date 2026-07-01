<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientProject;
use Illuminate\Support\Arr;

/**
 * Edit a deal's particulars (chosen project/unit, agreed price). The `stage` is
 * intentionally NOT changed here — stage moves go through AdvanceClientProjectStage
 * so the transition rules are always applied.
 */
class UpdateClientProject
{
    public function handle(ClientProject $project, array $data): ClientProject
    {
        $project->update(Arr::only($data, ['location_id', 'unit_id', 'total_price']));

        return $project->fresh();
    }
}
