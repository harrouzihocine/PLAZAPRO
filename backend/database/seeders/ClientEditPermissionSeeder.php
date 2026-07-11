<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the clients.edit permission (split from
 * clients.manage — see RbacSeeder) for environments where the full RbacSeeder
 * must NOT run (it would create demo accounts with a default password).
 * On first creation, every role holding clients.manage is backfilled so
 * existing managers keep editing; re-running only refreshes name/description.
 *
 *   php artisan db:seed --class=ClientEditPermissionSeeder --force
 */
class ClientEditPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'clients.edit'],
            [
                'name' => 'Clients Edit',
                'group' => 'Clients',
                'description' => 'Edit a client\'s information (name, phone, profile and identity details). Reassigning the follow-up agent stays with "Clients Manage".',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'clients.manage'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'clients.edit'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with clients.edit: {$granted}");
    }
}
