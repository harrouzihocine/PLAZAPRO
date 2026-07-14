<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One-shot, idempotent seed of the units.sold_price permission (see a unit's
 * asking price after it is sold — without it, sold units show their prices
 * locked everywhere: tables, unit page, stacking plan, Excel export) for
 * environments where the full RbacSeeder must NOT run. On first creation the
 * permission is backfilled to roles holding units.manage — the inventory desk
 * that sets prices keeps seeing them; the owner then widens or tightens per
 * role in the role editor.
 *
 *   php artisan db:seed --class=UnitSoldPricePermissionSeeder --force
 */
class UnitSoldPricePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::updateOrCreate(
            ['slug' => 'units.sold_price'],
            [
                'name' => 'Units Sold Price',
                'group' => 'Units',
                'description' => 'See the asking price of a unit after it is sold. Without it, a sold unit\'s prices are hidden everywhere (tables, unit page, building plan, Excel export).',
            ],
        );

        if ($permission->wasRecentlyCreated) {
            Role::whereHas('permissions', fn ($q) => $q->where('slug', 'units.manage'))
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($permission->id));
        }

        $granted = Role::whereHas('permissions', fn ($q) => $q->where('slug', 'units.sold_price'))
            ->pluck('slug')->join(', ');

        $this->command?->info("roles with units.sold_price: {$granted}");
    }
}
