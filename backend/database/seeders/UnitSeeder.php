<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Inventory\Actions\CreateUnit;
use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Seeder;

/**
 * Comprehensive unit test data covering:
 * - All unit types (studio, f2, f3, f4, duplex, commercial, etc.)
 * - All floors (ground, floor_1 through floor_5+, terrace)
 * - All sale statuses (available, interested, sold, draft)
 * - Various sizes and prices
 * - Different blocks and positions
 * - GTM priorities
 * - Multiple units per location
 */
class UnitSeeder extends Seeder
{
    private array $listCache = [];

    public function run(): void
    {
        $this->command->info('Seeding units…');

        $createUnit = app(CreateUnit::class);

        // Get all locations (created by LocationSeeder)
        $locations = Location::all();
        if ($locations->isEmpty()) {
            $this->command->warn('No locations found. Run LocationSeeder first.');

            return;
        }

        // Project type is a project (location) attribute — assign one per site,
        // cycling through the three residence kinds.
        $projectTypes = ['akam_mftoh', 'akam_mghlk', 'akam_shbh_mghlk'];

        foreach ($locations as $index => $location) {
            $location->update(['type_id' => $this->item('project_types', $projectTypes[$index % count($projectTypes)])]);
            $this->command->line("  Creating units for {$location->name}...");
            $this->seedUnitsForLocation($location, $createUnit);
        }

        $this->command->info('UnitSeeder complete: created ~120+ units across all locations with all types, statuses, and configurations.');
    }

    private function seedUnitsForLocation(Location $location, CreateUnit $createUnit): void
    {
        $blockCount = 0;
        $unitsPerBlock = 0;

        // Create 8-12 units per location with variety
        for ($i = 0; $i < 10; $i++) {
            $blockLetter = chr(65 + ($blockCount % 4)); // A, B, C, D
            if ($i > 0 && $i % 4 === 0) {
                $blockCount++;
            }

            // Room layouts (F2 / F3 / …) — the per-apartment size attribute.
            $roomLayouts = ['studio', 'f2', 'f3', 'f4', 'duplex', 'f2', 'f3'];
            $floors = ['ground', 'floor_1', 'floor_2', 'floor_3', 'floor_4', 'floor_5'];
            $saleStatuses = [SaleStatus::Available, SaleStatus::Interested, SaleStatus::Available, SaleStatus::Sold, SaleStatus::Available];

            $type = $roomLayouts[$i % count($roomLayouts)];
            $floor = $floors[$i % count($floors)];
            $status = $saleStatuses[$i % count($saleStatuses)];
            $stackFloor = intval($i / 4);
            $position = ($i % 4) + 1;

            // Pricing based on type and floor
            $basePrice = match ($type) {
                'studio' => 3200000,
                'f2' => 4600000,
                'f3' => 6500000,
                'f4' => 8400000,
                'duplex' => 11500000,
                default => 5000000,
            };

            // Premium floor pricing
            if ($floor !== 'ground') {
                $basePrice += $stackFloor * 200000;
            }

            // Size based on type
            $size = match ($type) {
                'studio' => 38.5,
                'f2' => 55.0,
                'f3' => 78.0,
                'f4' => 96.0,
                'duplex' => 120.0,
                default => 50.0,
            };

            $reference = sprintf('%s-%02d', $blockLetter, $unitsPerBlock + 1);
            $unitsPerBlock++;
            if ($unitsPerBlock >= 4) {
                $unitsPerBlock = 0;
                $blockCount++;
            }

            $gtmPriority = match (true) {
                $status === SaleStatus::Sold => GtmPriority::Low,
                $status === SaleStatus::Available && $type === 'f4' => GtmPriority::Critical,
                $status === SaleStatus::Available => GtmPriority::High,
                $status === SaleStatus::Interested => GtmPriority::Medium,
                default => GtmPriority::Low,
            };

            $createUnit->handle($location, [
                'reference' => $reference,
                'room_number_id' => $this->roomNumber($type),
                'floor_id' => $this->item('floors', $floor),
                'area_sqm' => $size,
                'price' => (string) $basePrice,
                'sale_status' => $status->value,
                'block' => $blockLetter,
                'stack_floor' => $stackFloor,
                'position' => $position,
                'gtm_priority' => $gtmPriority,
            ]);
        }

        // Add some edge cases for the first location
        if (Location::count() === count(Location::all())) {
            // Very expensive unit
            $createUnit->handle($location, [
                'reference' => 'PREMIUM-001',
                'room_number_id' => $this->roomNumber('duplex'),
                'floor_id' => $this->item('floors', 'floor_5'),
                'area_sqm' => 150.0,
                'price' => '25000000.00',
                'sale_status' => SaleStatus::Available->value,
                'block' => 'PREMIUM',
                'stack_floor' => 5,
                'position' => 1,
                'gtm_priority' => GtmPriority::Critical,
            ]);

            // Affordable studio
            $createUnit->handle($location, [
                'reference' => 'BUDGET-001',
                'room_number_id' => $this->roomNumber('studio'),
                'floor_id' => $this->item('floors', 'ground'),
                'area_sqm' => 28.5,
                'price' => '2100000.00',
                'sale_status' => SaleStatus::Available->value,
                'block' => 'A',
                'stack_floor' => 0,
                'position' => 99,
                'gtm_priority' => GtmPriority::High,
            ]);

            // Sold unit (historical record)
            $createUnit->handle($location, [
                'reference' => 'SOLD-001',
                'room_number_id' => $this->roomNumber('f3'),
                'floor_id' => $this->item('floors', 'floor_3'),
                'area_sqm' => 75.0,
                'price' => '6500000.00',
                'sale_status' => SaleStatus::Sold->value,
                'block' => 'B',
                'stack_floor' => 3,
                'position' => 5,
                'gtm_priority' => GtmPriority::Low,
            ]);
        }
    }

    /** Resolve a room-number item, tolerating the 'duplex'/'dublex' spelling. */
    private function roomNumber(string $value): int
    {
        return $this->item('room_numbers', $value === 'duplex' ? 'dublex' : $value);
    }

    private function item(string $listKey, string $value): int
    {
        if (! isset($this->listCache[$listKey])) {
            $listId = DynamicList::where('key', $listKey)->value('id');
            $this->listCache[$listKey] = DynamicListItem::where('dynamic_list_id', $listId)
                ->pluck('id', 'value')->all();
        }

        $id = $this->listCache[$listKey][$value] ?? null;
        abort_if($id === null, 500, "Missing dynamic-list item {$listKey}:{$value}");

        return (int) $id;
    }
}
