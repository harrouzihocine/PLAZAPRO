<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Settings\Models\Role;

/**
 * Cancel (no-delete) a role. The Super Admin role is protected, and a role that
 * still has active users can't be cancelled — reassign them first so no user is
 * left without a valid role.
 */
class CancelRole
{
    public function handle(Role $role, string $reason): Role
    {
        abort_if($role->slug === 'super-admin', 422, 'The Super Admin role cannot be cancelled.');

        abort_if(
            $role->users()->where('status', RecordStatus::Active->value)->exists(),
            422,
            "Reassign this role's users before cancelling it.",
        );

        return $role->cancel($reason);
    }
}
