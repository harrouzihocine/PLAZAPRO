<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\User;

/**
 * Cancel (no-delete) a user. The row is kept and marked cancelled (audited via
 * LogsActivity). An admin cannot cancel their own account (no self-lockout).
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

        return $user->cancel($reason);
    }
}
