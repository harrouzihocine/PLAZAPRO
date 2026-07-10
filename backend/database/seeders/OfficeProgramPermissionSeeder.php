<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the Office Visits Program view permission
 * (split from oversight.pipeline — see RbacSeeder) for environments where the
 * full RbacSeeder must NOT run (it would create demo accounts with a default
 * password). On first creation the permission is backfilled to every role
 * holding oversight.pipeline — the week grid grew out of that monitor's
 * upcoming-office-visits section, so nobody loses on deploy what they could
 * see before. The owner then grants it per role (e.g. sales agents) in the
 * role editor; deciding approval requests stays with visits.dispatch.
 *
 *   php artisan db:seed --class=OfficeProgramPermissionSeeder --force
 */
class OfficeProgramPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'oversight.office_program'],
            [
                'name' => 'Oversight Office Program',
                'group' => 'Oversight',
                'description' => 'Open the Office Visits Program page — the week grid of scheduled office visits. View only: own clients by name, colleagues\' slots masked as booked. Deciding approval requests needs "Visits Dispatch".',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'oversight.pipeline'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'oversight.office_program'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with oversight.office_program: {$granted}");
    }
}
