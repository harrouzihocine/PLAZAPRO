<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\User;
use Illuminate\Support\Arr;

/**
 * Update a user. Still exactly one role. The password is only changed when a new
 * one is supplied (empty/absent leaves it untouched); it is hashed by the model
 * cast. Every change is audited via LogsActivity on the User model.
 *
 * Only a super admin may edit another super admin — otherwise an admin could
 * demote or reset the credentials of a super admin (a back-door removal).
 */
class UpdateUser
{
    public function handle(User $user, array $data, User $actor): User
    {
        abort_if(
            $user->isSuperAdmin() && ! $actor->isSuperAdmin(),
            403,
            'Only a super admin can modify a super admin.',
        );

        $attributes = Arr::only($data, [
            'name', 'email', 'username', 'role_id', 'department_id', 'phone', 'is_active',
        ]);

        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        $user->update($attributes);

        return $user->fresh(['role', 'department']);
    }
}
