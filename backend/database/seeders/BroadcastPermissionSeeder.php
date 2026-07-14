<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the notifications.broadcast permission (see
 * RbacSeeder) for production, where the full RbacSeeder must NOT run (it would
 * create demo accounts with a default password).
 *
 * Deliberately NOT split-from-legacy: broadcasting a message to the whole
 * company is a new, sensitive capability, so only the admin-tier baseline roles
 * get it on first creation; the owner grants any other role in the role editor.
 * Re-running only refreshes name/description — an owner unticking the grant
 * later is respected.
 *
 *   php artisan db:seed --class=BroadcastPermissionSeeder --force
 */
class BroadcastPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'notifications.broadcast'],
            [
                'name' => 'Notifications Broadcast',
                'group' => 'Notifications',
                'description' => 'Write a custom message and send it as a notification to selected users, a whole role, or everyone — each recipient reads it in their own language. Includes the sent-broadcast history with read tracking.',
            ],
        );

        // First creation only — re-runs (ProductionSeeder rides every deploy)
        // must respect an owner who unticked the grant in the role editor.
        if ($permission->wasRecentlyCreated) {
            Role::whereIn('slug', ['super-admin', 'admin', 'manager'])
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'notifications.broadcast'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with notifications.broadcast: {$granted}");
    }
}
