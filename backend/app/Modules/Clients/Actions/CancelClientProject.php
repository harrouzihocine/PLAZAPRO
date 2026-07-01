<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientProject;

/**
 * Cancel (no-delete) a deal. The row and its history are kept and marked
 * cancelled (audited via LogsActivity).
 */
class CancelClientProject
{
    public function handle(ClientProject $project, string $reason): ClientProject
    {
        return $project->cancel($reason);
    }
}
