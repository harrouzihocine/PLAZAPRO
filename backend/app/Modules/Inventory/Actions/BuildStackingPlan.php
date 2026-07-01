<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Location;

/**
 * Build the visual stacking plan for a location: its active units grouped by
 * block → floor → position, each carrying its live sale_status. This is a pure
 * read view over `units` (no extra table). Floors are returned top-down so the
 * grid renders like a real building elevation.
 */
class BuildStackingPlan
{
    /**
     * @return list<array{block: string, floors: list<array{floor: int|null, units: list<array<string, mixed>>}>}>
     */
    public function handle(Location $location): array
    {
        $units = $location->units()
            ->active()
            ->with('activeReservation:id,unit_id,expires_at')
            ->orderByDesc('stack_floor')
            ->orderBy('position')
            ->orderBy('reference')
            ->get(['id', 'reference', 'sale_status', 'price', 'block', 'stack_floor', 'position']);

        return $units
            ->groupBy(fn ($unit) => $unit->block ?? 'Unassigned')
            ->map(fn ($blockUnits, $block) => [
                'block' => (string) $block,
                'floors' => $blockUnits
                    ->groupBy('stack_floor')
                    ->map(fn ($floorUnits, $floor) => [
                        'floor' => $floor === '' ? null : (int) $floor,
                        'units' => $floorUnits->map(fn ($unit) => [
                            'id' => $unit->id,
                            'reference' => $unit->reference,
                            'sale_status' => $unit->sale_status?->value,
                            'price' => $unit->price,
                            'position' => $unit->position,
                            'reservation_id' => $unit->activeReservation?->id,
                            'expires_at' => $unit->activeReservation?->expires_at,
                        ])->values()->all(),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
