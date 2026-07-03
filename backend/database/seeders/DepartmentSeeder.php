<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Baseline organisational departments for a real-estate promoter, and a sensible
 * default department for each seeded staff account (by role).
 *
 * Reference data — wired into DatabaseSeeder and safe to run in every
 * environment. Idempotent: departments are firstOrCreate'd by slug (the 'ventes'
 * slug is shared with DemoSeeder, so they reconcile), and staff are only assigned
 * a department when they don't already have one (never clobbers a manual choice).
 *
 * Runs after RbacSeeder so the staff accounts it assigns already exist.
 */
class DepartmentSeeder extends Seeder
{
    /** @var array<string, string> slug => display name */
    private array $departments = [
        'direction' => 'Direction',
        'ventes' => 'Ventes',
        'marketing' => 'Marketing',
        'finance' => 'Finance',
        'administration' => 'Administration',
    ];

    /**
     * Which department each role belongs to by default.
     *
     * @var array<string, string> role slug => department slug
     */
    private array $roleDepartment = [
        'super-admin' => 'administration',
        'admin' => 'administration',
        'manager' => 'direction',
        'sales-agent' => 'ventes',
        'site-agent' => 'ventes',
        'financer' => 'finance',
    ];

    public function run(): void
    {
        $departments = [];
        foreach ($this->departments as $slug => $name) {
            $departments[$slug] = Department::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        // Assign each seeded user their role's default department, but only when
        // they have none yet — keep it idempotent and non-destructive. Done via
        // the query builder so it doesn't flood the activity log.
        foreach ($this->roleDepartment as $roleSlug => $deptSlug) {
            $updated = DB::table('users')
                ->whereNull('department_id')
                ->whereIn('role_id', fn ($q) => $q->select('id')->from('roles')->where('slug', $roleSlug))
                ->update(['department_id' => $departments[$deptSlug]->id, 'updated_at' => now()]);

            if ($updated > 0) {
                $this->command?->info("Assigned {$updated} {$roleSlug} user(s) to {$deptSlug}.");
            }
        }

        $this->command?->info(sprintf('Departments ready: %d.', count($departments)));
    }
}
