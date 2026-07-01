<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\Role;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Update a role. `slug` is immutable (RBAC and the super-admin bypass key off
 * it). Permission changes are synced and explicitly logged so every role change
 * is auditable.
 */
class UpdateRole
{
    public function handle(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $role->update(Arr::only($data, ['name', 'description', 'is_agent']));

            if (array_key_exists('permissions', $data)) {
                $role->permissions()->sync($data['permissions'] ?? []);
                $role->logActivity('permissions', ['permissions' => array_values($data['permissions'] ?? [])]);
            }

            return $role->fresh('permissions');
        });
    }
}
