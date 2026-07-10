<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the tasks.assign team layer (see RbacSeeder)
 * for environments where the full RbacSeeder must NOT run (it would create
 * demo accounts with a default password). Deliberately NOT backfilled from
 * tasks.manage: restricting who hands out work is the point of the split —
 * only the admin-tier baseline roles get it; the owner grants any other role
 * in the role editor. Without it, tasks.manage users work their own list only.
 *
 *   php artisan db:seed --class=TasksAssignPermissionSeeder --force
 */
class TasksAssignPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'tasks.assign'],
            [
                'name' => 'Tasks Assign',
                'group' => 'Tasks',
                'description' => 'Create tasks for other users and see (and cancel) the whole team\'s tasks — without it, users work their own list only.',
            ],
        );

        Role::whereIn('slug', ['super-admin', 'admin', 'manager'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'tasks.assign'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with tasks.assign: {$granted}");
    }
}
