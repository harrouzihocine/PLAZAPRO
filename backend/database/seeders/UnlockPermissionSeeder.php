<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the users.unlock permission (split from
 * users.manage — see RbacSeeder) for environments where the full RbacSeeder
 * must NOT run (it would create demo accounts with a default password).
 * On first creation, every role holding users.manage is backfilled so
 * existing admins keep working; re-running only refreshes name/description.
 *
 *   php artisan db:seed --class=UnlockPermissionSeeder --force
 */
class UnlockPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'users.unlock'],
            [
                'name' => 'Users Unlock',
                'group' => 'Users',
                'description' => 'Unlock an account that was locked after too many failed sign-in attempts.',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'users.manage'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'users.unlock'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with users.unlock: {$granted}");
    }
}
