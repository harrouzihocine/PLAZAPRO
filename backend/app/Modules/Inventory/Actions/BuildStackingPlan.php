<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\User;

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
    public function handle(Location $location, ?User $viewer = null): array
    {
        $units = $location->units()
            ->active()
            ->with([
                'activeReservation:id,unit_id,expires_at',
                'activeReservations:id,unit_id,client_project_id',
            ])
            ->orderByDesc('stack_floor')
            ->orderBy('position')
            ->orderBy('reference')
            ->get(['id', 'reference', 'sale_status', 'price_semi_fini', 'price_fini', 'block', 'stack_floor', 'position', 'reserved_expires_at']);

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
                            // Compact grid: one number — semi-fini first. Sold
                            // prices are privileged (units.sold_price).
                            'price' => $unit->pricesVisibleTo($viewer) ? $unit->displayPrice() : null,
                            'position' => $unit->position,
                            'reservation_id' => $unit->activeReservation?->id,
                            // Interest-hold countdown, or the deposit
                            // countdown when the unit is reserved.
                            'expires_at' => $unit->sale_status === SaleStatus::Reserved
                                ? $unit->reserved_expires_at
                                : $unit->activeReservation?->expires_at,
                            'interested_count' => $unit->activeReservations
                                ->pluck('client_project_id')->filter()->unique()->count(),
                        ])->values()->all(),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
