<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\User;
use Illuminate\Support\Arr;

/**
 * Update a user. Still exactly one role. The password is only changed when a new
 * one is supplied (empty/absent leaves it untouched); it is hashed by the model
 * cast. Every change is audited via LogsActivity on the User model.
 */
class UpdateUser
{
    public function handle(User $user, array $data): User
    {
        $attributes = Arr::only($data, [
            'name', 'email', 'role_id', 'department_id', 'phone', 'is_active',
        ]);

        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        $user->update($attributes);

        return $user->fresh(['role', 'department']);
    }
}
