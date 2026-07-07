<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Settings\Models\User;

/**
 * Clear a brute-force login lock (and the failed-attempt counter) so the user
 * can sign in again. A lock never expires on its own (owner's rule) — only a
 * holder of users.unlock (see routes) clears it, and the unlock is audited
 * with the acting user. If every unlock-holder ever locks themselves out,
 * `php artisan user:unlock {login}` is the break-glass path.
 */
class UnlockUser
{
    public function handle(User $user): User
    {
        abort_if(
            $user->locked_at === null,
            422,
            'This account is not locked.',
        );

        $user->clearLoginLockout();

        ActivityLog::record('account_unlocked', $user);

        return $user->fresh(['role', 'department']);
    }
}
