<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientProject;

/**
 * Cancel/archive (no-delete) a deal. The row and its history are kept and marked
 * cancelled (audited via LogsActivity).
 *
 * Archive-only-without-payments (Phase 4, a guide test rule): a deal that has
 * active versements cannot be archived — the money must be resolved (corrected)
 * first, so recorded payments are never orphaned by a cancelled parent.
 */
class CancelClientProject
{
    public function handle(ClientProject $project, string $reason): ClientProject
    {
        abort_if(
            $project->versements()->active()->exists(),
            422,
            'This deal has recorded payments and cannot be archived. Resolve the versements first.'
        );

        return $project->cancel($reason);
    }
}
