<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the two Reservations-board permissions (split
 * from broader grants — see RbacSeeder) for environments where the full
 * RbacSeeder must NOT run (it would create demo accounts with a default
 * password). On first creation each permission is backfilled to the roles
 * holding its legacy parent, so nobody loses on deploy what they could do
 * before — the owner then tightens per role in the role editor:
 *
 *  - reservations.view      ← units.view      (the board used to ride it)
 *  - reservations.view_all  ← projects.view_all (sees every project ⇒ kept
 *                             seeing every queue; untick to self-scope agents)
 *
 *   php artisan db:seed --class=ReservationPermissionSeeder --force
 */
class ReservationPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $splits = [
            'reservations.view' => [
                'legacy' => 'units.view',
                'name' => 'Reservations View',
                'description' => 'Open the Reservations follow-up board — the waiting line on each reserved or held unit. Without "see all", it shows only queues involving your own clients and projects.',
            ],
            'reservations.view_all' => [
                'legacy' => 'projects.view_all',
                'name' => 'Reservations View All',
                'description' => 'See every reservation queue company-wide on the Reservations board, not just the ones involving your own clients and projects.',
            ],
        ];

        foreach ($splits as $slug => $def) {
            $permission = Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $def['name'],
                    'group' => 'Reservations',
                    'description' => $def['description'],
                ],
            );

            if ($permission->wasRecentlyCreated) {
                Role::whereHas('permissions', fn ($q) => $q->where('slug', $def['legacy']))
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
            }

            $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', $slug))
                ->pluck('slug')->join(', ');

            $this->command?->info("roles with {$slug}: {$granted}");
        }
    }
}
