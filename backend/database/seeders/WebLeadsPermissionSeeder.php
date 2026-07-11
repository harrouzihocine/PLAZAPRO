<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the web.leads permission (the public website's
 * leads inbox) for environments where the full RbacSeeder must NOT run (it
 * would create demo accounts with a default password). On first creation the
 * permission is backfilled to roles holding clients.manage — the back-office
 * desk that already owns client intake; the owner then widens/tightens per
 * role in the role editor.
 *
 *   php artisan db:seed --class=WebLeadsPermissionSeeder --force
 */
class WebLeadsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'web.leads'],
            [
                'name' => 'Web Leads',
                'group' => 'Website',
                'description' => 'Open the website leads inbox: visitor requests from the public site, with notifications on arrival and one-click conversion into clients.',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'clients.manage'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'web.leads'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with web.leads: {$granted}");
    }
}
