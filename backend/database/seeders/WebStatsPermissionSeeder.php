<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the web.stats permission (the public website's
 * statistics board) for environments where the full RbacSeeder must NOT run.
 * On first creation the permission is backfilled to roles holding web.leads —
 * the desk already watching the website's inbox; the owner then widens or
 * tightens per role in the role editor.
 *
 *   php artisan db:seed --class=WebStatsPermissionSeeder --force
 */
class WebStatsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'web.stats'],
            [
                'name' => 'Web Stats',
                'group' => 'Website',
                'description' => 'Open the website statistics board: visits, most-viewed projects and units, and contact clicks on the public site.',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'web.leads'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'web.stats'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with web.stats: {$granted}");
    }
}
