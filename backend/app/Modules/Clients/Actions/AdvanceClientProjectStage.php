<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\ClientProject;

/**
 * Move a deal to a new stage, enforcing the legal transitions defined on
 * ClientProjectStage (you go forward or drop to lost; won/lost are terminal).
 */
class AdvanceClientProjectStage
{
    public function handle(ClientProject $project, ClientProjectStage $target): ClientProject
    {
        $current = $project->stage;

        abort_if(
            ! $current->canTransitionTo($target),
            422,
            "A deal at '{$current->value}' cannot move to '{$target->value}'.",
        );

        $project->update(['stage' => $target->value]);

        return $project->fresh();
    }
}
