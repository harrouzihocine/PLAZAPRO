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
        // Settings / admin. users.unlock is split out of users.manage: clearing
        // a brute-force login lock, grantable on its own (e.g. to a manager)
        // without handing over full account administration.
        'users.manage', 'users.unlock', 'roles.manage', 'settings.manage', 'audit.view', 'audit.export',
        // Inventory
        'locations.manage', 'units.view', 'units.interest', 'units.manage', 'media.manage',
        // Clients — the client record itself. clients.manage covers ONLY the
        // client (edit / reassign / archive); projects have their own grants.
        'clients.view', 'clients.create', 'clients.manage',
        // Resolve duplicate-phone create attempts (deny / share a project) so no
        // user can silently take another user's client.
        'clients.duplicates.resolve',
        // Without view_all a user sees only the clients they created / follow up;
        // without view_details they see only the client's name (no phone/profile).
        'clients.view_all', 'clients.view_details',
        // Client projects — the project record & its lifecycle. projects.create
        // opens a new project (the agent lead flow); projects.manage edits its
        // particulars, archives / reactivates, shifts back to desire and removes
        // an empty project. Without projects.view_all a user sees only the
        // projects they created or were added to; projects.contributors allows
        // sharing a project (add/hide people on its visibility list);
        // projects.freeze closes a project to new activity (and reopens it) —
        // payments still flow on a frozen project.
        'projects.create', 'projects.manage',
        'projects.view_all', 'projects.contributors', 'projects.freeze',
        // Client project details — working inside one project's file:
        // projects.advance moves the workflow step; deals.manage is the closure
        // desk (close a deal / apartment won or lost, release a won apartment,
        // add boxes, record shortlist outcomes); deals.direct opens a deal
        // without a visit log.
        'projects.advance', 'deals.direct', 'deals.manage',
        // shortlist.manage: add properties to a project's shortlist while logging
        // an office visit. Split off so agents (who add via "Add unit to visit")
        // can be kept out of it while managers keep the office-visit picker.
        'shortlist.manage',
        // visits.dispatch: the weekly board — sees the pending (unassigned)
        // in-site pool and hands tasks to field agents. NOTE: the board is by
        // nature company-wide (client names, sites, agent workload across ALL
        // visibility scopes) — grant it only to roles trusted with that view.
        // visits.propose: the standalone "Add unit to visit" button (send
        // apartment(s) out as in-site visits, pooled or pre-assigned).
        // next_actions.plan: the standalone "Plan next action" button (a log's
        // own next action still rides on calls.log / visits.conduct).
        'calls.log', 'next_actions.plan',
        'visits.assign', 'visits.dispatch', 'visits.conduct', 'visits.propose', 'tasks.manage',
        // Payments
        'versements.view', 'versements.record', 'versements.cancel', 'documents.generate',
        // Collaboration & analytics
        // Project-chat oversight, two levels (neither touches direct/group chats):
        //  - chat.view_project_chats: READ any client-project chat without being
        //    a contributor;
        //  - chat.participate_project_chats: read AND write in any project chat
        //    (implies view).
        'chat.use', 'chat.view_project_chats', 'chat.participate_project_chats',
        'notifications.view', 'dashboard.view', 'reports.view',
        // Team-oversight monitors (follow up anomalies / lazy work), one per area.
        'oversight.clients', 'oversight.pipeline', 'oversight.deals', 'oversight.drafts',
        // The desire-matches board: waiting clients whose wishlist now fits available
        // inventory — the company-wide reconnect signal, an oversight monitor.
        'oversight.matches',
        // The archive desk: review + reactivate archived (lost/closed) projects.
        'oversight.archive',
        // The dashboard is personal for everyone (own book only). logs.view_all
        // unlocks the company-wide Team Logs page — every user's rapports (calls /
        // office & in-site visits) and planned work across ALL visibility scopes.
        'logs.view_all',
    ];

    /**
     * Plain-language explanation of what each permission grants, shown next to
     * the checkbox in the role editor so admins understand exactly what they
     * hand out. Any slug missing here falls back to a headline of its slug.
     *
     * @var array<string, string>
     */
    private array $permissionDescriptions = [
        // Settings / admin
        'users.manage' => 'Create, edit and deactivate staff accounts and set each person\'s role.',
        'users.unlock' => 'Unlock an account that was locked after too many failed sign-in attempts.',
        'roles.manage' => 'Create roles and choose exactly what each role is allowed to do.',
        'settings.manage' => 'Edit workspace settings: dropdown lists, wilayas & communes, departments and general options.',
        'audit.view' => 'Open the audit trail and see who changed what.',
        'audit.export' => 'Download the audit trail as a file.',
        // Inventory
        'locations.manage' => 'Add and edit buildings, sites and their locations.',
        'units.view' => 'See apartments/units and their availability.',
        'units.interest' => 'Mark a unit as Interested for a client (places a hold).',
        'units.manage' => 'Add, edit and change the status of units.',
        'media.manage' => 'Upload and manage photos and files on units and projects.',
        // Clients
        'clients.view' => 'Open the clients area.',
        'clients.create' => 'Add new clients and capture what they are looking for.',
        'clients.manage' => 'Edit, reassign and archive clients.',
        'clients.duplicates.resolve' => 'Decide what happens when a phone already belongs to another client (share or block).',
        'clients.view_all' => 'See every client in the company, not just your own.',
        'clients.view_details' => 'See a client\'s full profile (phone, details) — without it you see only the name.',
        // Client projects
        'projects.create' => 'Open a new project (file) for a client.',
        'projects.manage' => 'Edit a project\'s particulars, archive or reactivate it, shift it back to a desire, and remove an empty project.',
        'projects.view_all' => 'See every client project, not just the ones you created or were added to.',
        'projects.contributors' => 'Share a project: add or hide people on its access list.',
        'projects.freeze' => 'Freeze a project (no new calls, visits, deals or chat — payments still flow) and unfreeze it.',
        // Client project details
        'projects.advance' => 'Move a project\'s workflow step forward or back.',
        'deals.direct' => 'Open a deal directly without going through a visit log.',
        'deals.manage' => 'Close a deal or one of its apartments (won / lost), release a won apartment, add boxes and record shortlist outcomes.',
        'shortlist.manage' => 'Curate a project\'s standalone shortlist panel: add and drop properties directly, outside any visit log. (Anyone completing an office visit can add properties inside that log.)',
        'calls.log' => 'Record call rapports with clients.',
        'next_actions.plan' => 'Use the "Plan next action" button to schedule a follow-up (call, office or in-site visit) when nothing is pending.',
        'visits.assign' => 'Assign visits to agents.',
        'visits.dispatch' => 'Use the weekly dispatch board and hand out field visits company-wide.',
        'visits.conduct' => 'Carry out visits and write their rapports.',
        'visits.propose' => 'Use the "Add unit to visit" button — send apartment(s) out as in-site visits (into the dispatch pool; dispatchers may pre-assign the agent).',
        'tasks.manage' => 'Create and manage follow-up tasks.',
        // Payments
        'versements.view' => 'See payment records (versements) and schedules.',
        'versements.record' => 'Record incoming payments.',
        'versements.cancel' => 'Cancel or refund a recorded payment.',
        'documents.generate' => 'Generate branded documents (contracts, receipts).',
        // Collaboration & analytics
        'chat.use' => 'Use chat — direct, group and project conversations.',
        'chat.view_project_chats' => 'Read any client-project chat without being a member (oversight).',
        'chat.participate_project_chats' => 'Read and write in any project chat without being a member.',
        'notifications.view' => 'Receive and see notifications.',
        'dashboard.view' => 'See the personal dashboard.',
        'reports.view' => 'Open analytics reports.',
        'oversight.clients' => 'Monitor client-handling anomalies across the team.',
        'oversight.pipeline' => 'Monitor pipeline and visit anomalies across the team.',
        'oversight.deals' => 'Monitor deal anomalies (e.g. stale holds) across the team.',
        'oversight.drafts' => 'Monitor abandoned drafts across the team.',
        'oversight.matches' => 'Open the company-wide Desire Matches board: waiting clients whose wishlist now fits available inventory.',
        'oversight.archive' => 'Review and reactivate archived (lost/closed) projects across the team.',
        'logs.view_all' => 'Open the company-wide Team Logs (everyone\'s calls & visits).',
    ];

    /**
     * Explicit group labels for the role-editor matrix where the slug prefix
     * alone doesn't say enough. Clients, their projects and the work inside a
     * project's file are deliberately separate groups so an admin can hand out
     * (say) client editing without project closing. Any slug missing here
     * falls back to a headline of its prefix.
     *
     * @var array<string, string>
     */
    private array $permissionGroups = [
        'clients.view' => 'Clients',
        'clients.create' => 'Clients',
        'clients.manage' => 'Clients',
        'clients.duplicates.resolve' => 'Clients',
        'clients.view_all' => 'Clients',
        'clients.view_details' => 'Clients',
        'projects.create' => 'Client Projects',
        'projects.manage' => 'Client Projects',
        'projects.view_all' => 'Client Projects',
        'projects.contributors' => 'Client Projects',
        'projects.freeze' => 'Client Projects',
        'projects.advance' => 'Client Project Details',
        'deals.direct' => 'Client Project Details',
        'deals.manage' => 'Client Project Details',
        'shortlist.manage' => 'Client Project Details',
    ];

    /**
     * New permissions split out of a broader legacy grant. When one of these is
     * seeded for the FIRST time, every role holding the legacy slug is given
     * the new one too, so existing roles (incl. custom ones made in the role
     * editor) keep doing what they could before the split. Runs only on
     * creation — an admin unticking the new grant later is respected.
     *
     * @var array<string, string> new slug => legacy slug it was split from
     */
    private array $splitFromLegacy = [
        'projects.create' => 'clients.create',
        'projects.manage' => 'clients.manage',
        'projects.advance' => 'clients.manage',
        'deals.manage' => 'clients.manage',
        // Split from deals.manage: every management role that could close deals
        // keeps the office-visit shortlist picker; agents (no deals.manage) don't.
        'shortlist.manage' => 'deals.manage',
        // Split so the two standalone timeline buttons ("Plan next action" /
        // "Add unit to visit") can be granted person-by-person. Roles that could
        // use them before (via the broad grant) keep doing so.
        'next_actions.plan' => 'calls.log',
        'visits.propose' => 'visits.conduct',
        // Split from users.manage: clearing a brute-force login lock without
        // full account administration.
        'users.unlock' => 'users.manage',
    ];

    /**
     * Plain-language summary of each baseline role, seeded once (only when the
     * role has no description yet, so manual edits are preserved).
     *
     * @var array<string, string>
     */
    private array $roleDescriptions = [
        'super-admin' => 'Full control of everything, including other admins. Reserved for one person.',
        'admin' => 'Full control of the app, but cannot modify or remove a super admin.',
        'manager' => 'Sees every rapport and all analytics, oversees the team, and can be assigned visits.',
        'sales-agent' => 'Handles calls and office visits with clients.',
        'site-agent' => 'The field agent — carries out in-site apartment visits.',
        'financer' => 'The payment desk — records payments, manages schedules and generates documents.',
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
            $slug => Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => Str::headline(str_replace('.', ' ', $slug)),
                    'group' => $this->permissionGroups[$slug] ?? Str::headline(Str::before($slug, '.')),
                    'description' => $this->permissionDescriptions[$slug] ?? null,
                ],
            ),
        ]);

        $this->backfillSplitPermissions($permissions);

        $roles = $this->seedRoles($permissions);

        $this->seedUsers($roles);
    }

    /**
     * Grant each freshly split permission to the roles holding its legacy
     * parent (see $splitFromLegacy). Baseline roles are re-synced right after
     * anyway; this protects custom roles built in the role editor.
     *
     * @param  Collection<string, Permission>  $permissions
     */
    private function backfillSplitPermissions(Collection $permissions): void
    {
        foreach ($this->splitFromLegacy as $new => $legacy) {
            $permission = $permissions[$new];
            if (! $permission->wasRecentlyCreated) {
                continue;
            }
            Role::whereHas('permissions', fn ($q) => $q->where('slug', $legacy))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }
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
        $siteAgent = [
            ...$this->baseline, ...$fullVisibility,
            'clients.view', 'units.view', 'visits.conduct', 'visits.propose',
        ];

        // Calls + office-visit rapports. Opening a project is part of the lead
        // workflow (the "New project" flow starts with its opening call).
        $salesAgent = [
            ...$this->baseline, ...$fullVisibility,
            'clients.view', 'clients.create', 'projects.create',
            'calls.log', 'next_actions.plan', 'visits.conduct', 'visits.propose',
        ];

        // The payment desk: record versements, manage schedules, generate documents.
        $financer = [
            ...$this->baseline, ...$fullVisibility, 'clients.view',
            'versements.view', 'versements.record', 'versements.cancel', 'documents.generate',
        ];

        // Every rapport type + all analytics reports + operational oversight
        // (incl. reading AND writing in any project chat without being a
        // contributor — untick participate to fall back to read-only view).
        $manager = [
            ...$this->baseline, ...$fullVisibility, 'reports.view', 'logs.view_all',
            'oversight.clients', 'oversight.pipeline', 'oversight.deals', 'oversight.drafts',
            'oversight.archive', 'oversight.matches',
            'chat.view_project_chats', 'chat.participate_project_chats',
            'clients.view', 'clients.create', 'clients.manage', 'clients.duplicates.resolve',
            'projects.create', 'projects.manage', 'projects.contributors', 'projects.freeze',
            'projects.advance', 'deals.direct', 'deals.manage', 'shortlist.manage',
            'calls.log', 'next_actions.plan',
            'visits.assign', 'visits.dispatch', 'visits.conduct', 'visits.propose', 'tasks.manage',
            'units.view', 'units.interest', 'units.manage', 'media.manage',
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
            // Backfill the plain-language description once, without clobbering a
            // description an admin may have edited on an existing role.
            if (blank($role->description) && isset($this->roleDescriptions[$slug])) {
                $role->forceFill(['description' => $this->roleDescriptions[$slug]])->save();
            }
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
                    // Login handle derived from the email local-part (e.g. admin1).
                    'username' => Str::before($data['email'], '@'),
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
                    'role_id' => $roles[$data['role']]->id,
                    'is_active' => true,
                ],
            );
        }
    }
}
