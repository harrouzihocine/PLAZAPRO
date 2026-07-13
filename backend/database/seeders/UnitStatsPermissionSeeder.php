<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the units.stats permission (the statistics /
 * performance tab and the Voice-of-Client analytics on project and unit pages)
 * for environments where the full RbacSeeder must NOT run. On first creation
 * the permission is backfilled to roles holding reports.view — the managers who
 * already saw Voice of Client; the owner then widens or tightens per role in
 * the role editor.
 *
 *   php artisan db:seed --class=UnitStatsPermissionSeeder --force
 */
class UnitStatsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'units.stats'],
            [
                'name' => 'Units Stats',
                'group' => 'Units',
                'description' => 'See the statistics and Voice of Client tabs on project and unit pages (performance counters, pipeline movement, log-mined feedback analytics).',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'reports.view'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'units.stats'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with units.stats: {$granted}");
    }
}
