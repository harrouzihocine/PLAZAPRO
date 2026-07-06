<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Inventory\Actions\CreateLocation;
use App\Modules\Inventory\Actions\CreateUnit;
use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Inventory-only sample data: a handful of real-estate projects (locations)
 * spread across wilayas, each with a few units. Lighter than DemoSeeder (no
 * clients/deals/payments/chat) — useful to populate inventory screens fast.
 *
 * Everything goes through the real domain Actions so derived state
 * (sale_status, gtm_priority) matches what the app would produce.
 * NOT wired into DatabaseSeeder — run explicitly: `php artisan db:seed --class=InventorySeeder`.
 * Idempotent by a skip-guard on the first seeded location code.
 */
class InventorySeeder extends Seeder
{
    /** @var array<string, array<string, int>> memoised dynamic-list value => id */
    private array $listCache = [];

    public function run(): void
    {
        $admin = User::where('email', 'admin@plaza.local')->first();
        if ($admin === null) {
            $this->command->warn('Base seeders must run first (admin user missing). Aborting inventory seed.');

            return;
        }

        if (Location::where('code', 'CEB')->exists()) {
            $this->command->info('Inventory demo data already present — nothing to do.');

            return;
        }

        // Give the audit trail (LogsActivity) a real actor for every write below.
        Auth::login($admin);

        $this->command->info('Seeding inventory data…');

        $createLocation = app(CreateLocation::class);
        $createUnit = app(CreateUnit::class);

        $projects = [
            ['name' => 'Cité El Baraka', 'code' => 'CEB', 'wilaya' => 'Constantine', 'address' => 'Boulevard Zighoud Youcef, Constantine', 'description' => 'Mid-range project, city centre.', 'priority' => GtmPriority::Medium],
            ['name' => 'Les Terrasses de Blida', 'code' => 'LTB', 'wilaya' => 'Blida', 'address' => 'Route Nationale 1, Blida', 'description' => 'Green residence at the foot of the Atlas.', 'priority' => GtmPriority::Low],
            ['name' => 'Résidence Annassim', 'code' => 'RAN', 'wilaya' => 'Alger', 'address' => 'Cité Annassim, Alger', 'description' => 'New-build residence, high demand area.', 'priority' => GtmPriority::High],
            ['name' => 'Domaine El Manar', 'code' => 'DEM', 'wilaya' => 'Sétif', 'address' => 'Route de Aïn Oulmène, Sétif', 'description' => 'Large mixed-use development, phase 1.', 'priority' => GtmPriority::Medium],
        ];

        $blocks = ['A', 'B', 'C', 'D'];
        // Room layouts (per-apartment size); project type is a location attribute.
        $roomLayouts = ['studio', 'f2', 'f3', 'f4', 'duplex'];
        $projectTypes = ['akam_mftoh', 'akam_mghlk', 'akam_shbh_mghlk'];
        $floors = ['ground', 'floor_1', 'floor_2', 'floor_3', 'floor_4', 'floor_5'];
        $areaByType = ['studio' => 38.0, 'f2' => 55.0, 'f3' => 78.0, 'f4' => 96.0, 'duplex' => 120.0];
        $priceByType = ['studio' => '3200000.00', 'f2' => '4600000.00', 'f3' => '6800000.00', 'f4' => '8400000.00', 'duplex' => '11500000.00'];

        $totalUnits = 0;

        foreach ($projects as $stackFloor => $project) {
            $location = $createLocation->handle([
                'name' => $project['name'],
                'code' => $project['code'],
                'wilaya_id' => $this->wilaya($project['wilaya']),
                'type_id' => $this->item('project_types', $projectTypes[$stackFloor % count($projectTypes)]),
                'address' => $project['address'],
                'description' => $project['description'],
                'gtm_priority' => $project['priority']->value,
            ]);

            $block = $blocks[$stackFloor % count($blocks)];

            for ($i = 1; $i <= 6; $i++) {
                $type = $roomLayouts[($i - 1) % count($roomLayouts)];
                $floor = $floors[($i - 1) % count($floors)];

                $createUnit->handle($location, [
                    'reference' => sprintf('%s-%02d', $block, $i),
                    'room_number_id' => $this->item('room_numbers', $type === 'duplex' ? 'dublex' : $type),
                    'floor_id' => $this->item('floors', $floor),
                    'area_sqm' => $areaByType[$type],
                    'price' => $priceByType[$type],
                    'block' => $block,
                    'stack_floor' => intdiv($i - 1, count($blocks)),
                    'position' => $stackFloor + 1,
                ]);
                $totalUnits++;
            }
        }

        Auth::logout();

        $this->command->info(sprintf('Inventory data seeded: %d projects, %d units.', count($projects), $totalUnits));
    }

    /** Resolve a dynamic-list item id by its list key + value (memoised). */
    private function item(string $listKey, string $value): int
    {
        if (! isset($this->listCache[$listKey])) {
            $listId = DynamicList::where('key', $listKey)->value('id');
            $this->listCache[$listKey] = DynamicListItem::where('dynamic_list_id', $listId)
                ->pluck('id', 'value')->all();
        }

        $id = $this->listCache[$listKey][$value] ?? null;
        abort_if($id === null, 500, "Missing dynamic-list item {$listKey}:{$value} — run DynamicListSeeder first.");

        return (int) $id;
    }

    /** Resolve a wilaya id by its name. */
    private function wilaya(string $name): int
    {
        $id = Wilaya::where('name', $name)->value('id');
        abort_if($id === null, 500, "Missing wilaya {$name} — run WilayaCommuneSeeder first.");

        return (int) $id;
    }
}
