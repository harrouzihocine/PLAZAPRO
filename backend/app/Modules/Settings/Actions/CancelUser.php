<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\User;

/**
 * Cancel (no-delete) a user. The row is kept and marked cancelled (audited via
 * LogsActivity). An admin cannot cancel their own account (no self-lockout), and
 * only a super admin may cancel another super admin.
 */
class CancelUser
{
    public function handle(User $user, User $actor, string $reason): User
    {
        abort_if(
            $user->is($actor),
            422,
            'You cannot cancel your own account.',
        );

        abort_if(
            $user->isSuperAdmin() && ! $actor->isSuperAdmin(),
            403,
            'Only a super admin can remove a super admin.',
        );

        // Revoke access immediately: drop every API token (the SPA session is
        // rejected on its next request by EnsureUserActive).
        $user->tokens()->delete();

        return $user->cancel($reason);
    }
}
