<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the per-workflow log-edit permissions (see
 * RbacSeeder) for production, where the full RbacSeeder must NOT run (it would
 * create demo accounts with a default password).
 *
 * Each grant is split out of a broader legacy one, so on FIRST creation every
 * role holding the legacy slug is backfilled — existing roles keep doing what
 * they could before the split (edit their call/visit rapports, re-plan the
 * follow-up, cancel a still-waiting deal). Re-running only refreshes
 * name/description; an owner unticking a grant later is respected.
 *
 *   php artisan db:seed --class=LogEditPermissionsSeeder --force
 */
class LogEditPermissionsSeeder extends Seeder
{
    /** @var array<string, array{name: string, description: string, legacy: string}> */
    private array $permissions = [
        'logs.edit_call' => [
            'name' => 'Logs Edit Call',
            'description' => 'Edit a logged call rapport. The original is cancelled and kept in history; the correction is a new version.',
            'legacy' => 'calls.log',
        ],
        'logs.edit_visit' => [
            'name' => 'Logs Edit Visit',
            'description' => 'Edit a completed visit rapport (office or in-site). The original is cancelled and kept; the correction is a new version.',
            'legacy' => 'visits.conduct',
        ],
        'logs.edit_next_action' => [
            'name' => 'Logs Edit Next Action',
            'description' => 'Change the pending next action a log created (its type, date/time or assignee). The old plan is cancelled and kept.',
            'legacy' => 'calls.log',
        ],
        'logs.cancel_deal' => [
            'name' => 'Logs Cancel Deal',
            'description' => 'When editing a log that had opened a deal still only reserved (no payment or sale), cancel that deal — its held apartments/boxes go back to the market — so the log can be corrected. A deal with a payment or a sale blocks the edit for everyone.',
            'legacy' => 'deals.manage',
        ],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $slug => $meta) {
            $permission = Permission::updateOrCreate(
                ['slug' => $slug],
                ['name' => $meta['name'], 'group' => 'Client Project Details', 'description' => $meta['description']],
            );

            if ($permission->wasRecentlyCreated) {
                Role::whereHas('permissions', fn ($q) => $q->where('slug', $meta['legacy']))
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
            }

            $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', $slug))
                ->pluck('slug')->join(', ');

            $this->command?->info("roles with {$slug}: {$granted}");
        }
    }
}
