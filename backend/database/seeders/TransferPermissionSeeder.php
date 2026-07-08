<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the users.transfer permission (split from
 * users.manage — see RbacSeeder) for environments where the full RbacSeeder
 * must NOT run (it would create demo accounts with a default password).
 * On first creation, every role holding users.manage is backfilled so
 * existing admins keep working; re-running only refreshes name/description.
 *
 *   php artisan db:seed --class=TransferPermissionSeeder --force
 */
class TransferPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'users.transfer'],
            [
                'name' => 'Users Transfer',
                'group' => 'Users',
                'description' => 'Review everything a (leaving) user owns and hand their open work — clients, projects, planned actions, visits, tasks — to a successor.',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'users.manage'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'users.transfer'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with users.transfer: {$granted}");
    }
}
