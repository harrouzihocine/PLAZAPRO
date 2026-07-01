<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RbacSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private array $permissions = [
        // Settings / admin
        'users.manage', 'roles.manage', 'settings.manage', 'audit.view', 'audit.export',
        // Inventory
        'units.view', 'units.reserve', 'units.manage', 'media.manage',
        // Clients & pipeline
        'clients.view', 'clients.create', 'clients.manage',
        'calls.log', 'visits.assign', 'visits.conduct', 'tasks.manage',
        // Payments
        'versements.view', 'versements.record', 'versements.cancel', 'documents.generate',
        // Collaboration & analytics
        'chat.use', 'notifications.view', 'dashboard.view',
    ];

    public function run(): void
    {
        $permissions = collect($this->permissions)->map(fn (string $slug) => Permission::firstOrCreate(
            ['slug' => $slug],
            ['name' => Str::headline(str_replace('.', ' ', $slug)), 'group' => Str::headline(Str::before($slug, '.'))],
        ));

        $admin = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Admin', 'is_agent' => false],
        );
        $admin->permissions()->sync($permissions->pluck('id'));

        $agent = Role::firstOrCreate(
            ['slug' => 'agent'],
            ['name' => 'Agent', 'is_agent' => true],
        );
        $agent->permissions()->sync(
            Permission::whereIn('slug', [
                'units.view', 'clients.view', 'clients.create', 'calls.log',
                'visits.conduct', 'tasks.manage', 'chat.use', 'notifications.view', 'dashboard.view',
            ])->pluck('id')
        );

        User::firstOrCreate(
            ['email' => 'admin@plaza.local'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('change-me-now'),
                'role_id' => $admin->id,
                'is_active' => true,
            ],
        );
    }
}
