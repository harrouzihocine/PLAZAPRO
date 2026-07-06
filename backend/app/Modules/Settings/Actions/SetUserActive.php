<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\User;

/**
 * Activate or deactivate a user (`is_active`). Deactivating keeps the row and
 * the login credentials intact — it only blocks access. An admin cannot
 * deactivate their own account (no self-lockout), and only a super admin may
 * deactivate another super admin.
 */
class SetUserActive
{
    public function handle(User $user, User $actor, bool $active): User
    {
        abort_if(
            ! $active && $user->is($actor),
            422,
            'You cannot deactivate your own account.',
        );

        abort_if(
            ! $active && $user->isSuperAdmin() && ! $actor->isSuperAdmin(),
            403,
            'Only a super admin can deactivate a super admin.',
        );

        $user->update(['is_active' => $active]);

        // Deactivating revokes access immediately: drop every API token so a
        // mobile client can't keep using a bearer token (the SPA session is
        // rejected on its next request by EnsureUserActive).
        if (! $active) {
            $user->tokens()->delete();
        }

        return $user->fresh(['role', 'department']);
    }
}
