<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Baseline roles, permissions and staff accounts.
 *
 * Roles (see docs) map to what each person may do in the app:
 *   - super-admin : everything. One person only.
 *   - admin       : everything, but cannot remove/modify a super admin
 *                   (enforced in the user actions, not by a permission).
 *   - manager     : every rapport (calls, office & outside visits) + all
 *                   analytics reports. Flagged is_agent so a manager can also
 *                   be assigned visits.
 *   - sales-agent : calls rapports + office-visit rapports.
 *   - site-agent  : outside (in-site / field) visit rapports only. The field agent —
 *                   flagged is_agent so they can be assigned visits.
 *   - financer    : the payment desk — records versements, manages schedules and
 *                   generates branded documents. Not an agent.
 *
 * Idempotent: roles/permissions/users are firstOrCreate'd and permissions are
 * synced, so re-running only reconciles the grants.
 */
class RbacSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private array $permissions = [
        // Settings / admin
        'users.manage', 'roles.manage', 'settings.manage', 'audit.view', 'audit.export',
        // Inventory
        'locations.manage', 'units.view', 'units.reserve', 'units.manage', 'media.manage',
        // Clients & pipeline
        'clients.view', 'clients.create', 'clients.manage',
        // Without view_all a user sees only the clients they created / follow up;
        // without view_details they see only the client's name (no phone/profile).
        'clients.view_all', 'clients.view_details',
        // Without projects.view_all a user sees only the projects they created or
        // were added to; projects.contributors allows sharing a project (add/hide
        // people on its visibility list).
        'projects.view_all', 'projects.contributors',
        // visits.dispatch: the weekly board — sees the pending (unassigned)
        // in-site pool and hands tasks to field agents.
        'calls.log', 'visits.assign', 'visits.dispatch', 'visits.conduct', 'tasks.manage',
        // A deal normally comes from a visit log; this allows opening one directly.
        'deals.direct',
        // Payments
        'versements.view', 'versements.record', 'versements.cancel', 'documents.generate',
        // Collaboration & analytics
        'chat.use', 'notifications.view', 'dashboard.view', 'reports.view',
    ];

    /**
     * Grants that are common to every operational role, so the app is usable
     * (see own dashboard, chat, notifications).
     *
     * @var list<string>
     */
    private array $baseline = ['dashboard.view', 'notifications.view', 'chat.use'];

    /**
     * Password for every seeded account. Development default — change it
     * immediately in any real environment.
     */
    private const DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        $permissions = collect($this->permissions)->mapWithKeys(fn (string $slug) => [
            $slug => Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline(str_replace('.', ' ', $slug)), 'group' => Str::headline(Str::before($slug, '.'))],
            ),
        ]);

        $roles = $this->seedRoles($permissions);

        $this->seedUsers($roles);
    }

    /**
     * @param  Collection<string, Permission>  $permissions
     * @return array<string, Role>
     */
    private function seedRoles(Collection $permissions): array
    {
        $all = $permissions->keys()->all();

        // Historic behavior: every role that views clients sees all of them, in
        // full detail, along with every project. Tighter roles (own-clients-only,
        // name-only) are built by unticking these in the role matrix.
        $fullVisibility = ['clients.view_all', 'clients.view_details', 'projects.view_all'];

        // Outside/apartment visit rapports (the field agent).
        $siteAgent = [...$this->baseline, ...$fullVisibility, 'clients.view', 'units.view', 'visits.conduct'];

        // Calls + office-visit rapports.
        $salesAgent = [
            ...$this->baseline, ...$fullVisibility,
            'clients.view', 'clients.create', 'calls.log', 'visits.conduct',
        ];

        // The payment desk: record versements, manage schedules, generate documents.
        $financer = [
            ...$this->baseline, ...$fullVisibility, 'clients.view',
            'versements.view', 'versements.record', 'versements.cancel', 'documents.generate',
        ];

        // Every rapport type + all analytics reports + operational oversight.
        $manager = [
            ...$this->baseline, ...$fullVisibility, 'reports.view',
            'clients.view', 'clients.create', 'clients.manage', 'projects.contributors',
            'calls.log', 'visits.assign', 'visits.dispatch', 'visits.conduct', 'tasks.manage', 'deals.direct',
            'units.view', 'units.reserve', 'units.manage', 'media.manage',
            'versements.view', 'versements.record', 'versements.cancel', 'documents.generate',
        ];

        $definitions = [
            'super-admin' => ['name' => 'Super Admin', 'is_agent' => false, 'grants' => $all],
            'admin' => ['name' => 'Admin', 'is_agent' => false, 'grants' => $all],
            'manager' => ['name' => 'Manager', 'is_agent' => true, 'grants' => $manager],
            'sales-agent' => ['name' => 'Sales Agent', 'is_agent' => false, 'grants' => $salesAgent],
            'site-agent' => ['name' => 'Site Agent', 'is_agent' => true, 'grants' => $siteAgent],
            'financer' => ['name' => 'Financer', 'is_agent' => false, 'grants' => $financer],
        ];

        $roles = [];
        foreach ($definitions as $slug => $def) {
            $role = Role::firstOrCreate(
                ['slug' => $slug],
                ['name' => $def['name'], 'is_agent' => $def['is_agent']],
            );
            $role->permissions()->sync($permissions->only($def['grants'])->pluck('id'));
            $roles[$slug] = $role;
        }

        return $roles;
    }

    /**
     * One super admin, two of every other role. The super admin keeps the
     * canonical admin@plaza.local address (the demo seeder looks it up).
     *
     * @param  array<string, Role>  $roles
     */
    private function seedUsers(array $roles): void
    {
        $users = [
            ['name' => 'Super Admin', 'email' => 'admin@plaza.local', 'role' => 'super-admin'],

            ['name' => 'Yasmine Admin', 'email' => 'admin1@plaza.local', 'role' => 'admin'],
            ['name' => 'Omar Admin', 'email' => 'admin2@plaza.local', 'role' => 'admin'],

            ['name' => 'Nassim Manager', 'email' => 'manager1@plaza.local', 'role' => 'manager'],
            ['name' => 'Leila Manager', 'email' => 'manager2@plaza.local', 'role' => 'manager'],

            ['name' => 'Sami Sales', 'email' => 'sales1@plaza.local', 'role' => 'sales-agent'],
            ['name' => 'Rania Sales', 'email' => 'sales2@plaza.local', 'role' => 'sales-agent'],

            ['name' => 'Bilal Field', 'email' => 'site1@plaza.local', 'role' => 'site-agent'],
            ['name' => 'Imene Field', 'email' => 'site2@plaza.local', 'role' => 'site-agent'],

            ['name' => 'Farid Finance', 'email' => 'finance1@plaza.local', 'role' => 'financer'],
        ];

        foreach ($users as $data) {
            User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
                    'role_id' => $roles[$data['role']]->id,
                    'is_active' => true,
                ],
            );
        }
    }
}
