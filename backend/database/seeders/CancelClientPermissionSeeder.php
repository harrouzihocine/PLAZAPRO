<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the clients.cancel permission (split from
 * clients.manage — see RbacSeeder) for environments where the full RbacSeeder
 * must NOT run (it would create demo accounts with a default password).
 * On first creation, every role holding clients.manage is backfilled so the
 * roles that could cancel a client before the split (super-admin / admin /
 * manager on prod) keep doing so; re-running only refreshes name/description.
 *
 *   php artisan db:seed --class=CancelClientPermissionSeeder --force
 */
class CancelClientPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'clients.cancel'],
            [
                'name' => 'Clients Cancel',
                'group' => 'Clients',
                'description' => 'Cancel a client (marks the record cancelled — it is kept and audited, never deleted).',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'clients.manage'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'clients.cancel'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with clients.cancel: {$granted}");
    }
}
