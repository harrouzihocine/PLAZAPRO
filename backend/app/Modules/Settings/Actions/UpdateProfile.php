<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\User;
use Illuminate\Support\Arr;

/**
 * Self-service profile update: a user editing their own account. Deliberately
 * narrow — only name, email, phone and password. Role, department, active-state
 * and the login username are off-limits here; those change only through the
 * admin Users screen (users.manage). Every change is audited via LogsActivity.
 */
class UpdateProfile
{
    public function handle(User $user, array $data): User
    {
        $attributes = Arr::only($data, ['name', 'email', 'phone']);

        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        $user->update($attributes);

        return $user->fresh(['role', 'department']);
    }
}
