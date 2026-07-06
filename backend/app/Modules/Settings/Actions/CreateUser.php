<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\User;
use Illuminate\Support\Arr;

/**
 * Create a system user. Exactly one role (`role_id` is required and singular),
 * an optional department. The password is hashed by the model cast. LogsActivity
 * (on the User model) records the creation.
 */
class CreateUser
{
    public function handle(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'],
            'password' => $data['password'],
            'role_id' => $data['role_id'],
            'department_id' => $data['department_id'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_active' => Arr::get($data, 'is_active', true),
        ]);

        return $user->load(['role', 'department']);
    }
}
