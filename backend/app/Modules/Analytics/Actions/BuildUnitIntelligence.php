<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Visit;

/**
 * Per-unit intelligence: interest (visits), holds (reservations), and conversion
 * (won deals) for each active unit. Pure read over units / visits / reservations
 * / client_projects. `heat` is the raw interest score the stacking-plan overlay
 * colours by. Optionally scoped to a single location.
 */
class BuildUnitIntelligence
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(?int $locationId = null): array
    {
        $units = Unit::query()->active()
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->with('location:id,name')
            ->get(['id', 'reference', 'location_id', 'sale_status', 'price']);

        $unitIds = $units->pluck('id');

        $visits = Visit::query()->active()
            ->whereIn('unit_id', $unitIds)
            ->selectRaw('unit_id, COUNT(*) as total')
            ->groupBy('unit_id')
            ->pluck('total', 'unit_id');

        $holds = Reservation::query()->active()
            ->whereIn('unit_id', $unitIds)
            ->whereIn('hold_status', ['active', 'converted'])
            ->selectRaw('unit_id, COUNT(*) as total')
            ->groupBy('unit_id')
            ->pluck('total', 'unit_id');

        $won = ClientProject::query()->active()
            ->where('stage', 'won')
            ->whereIn('unit_id', $unitIds)
            ->selectRaw('unit_id, COUNT(*) as total')
            ->groupBy('unit_id')
            ->pluck('total', 'unit_id');

        return $units
            ->map(function (Unit $u) use ($visits, $holds, $won): array {
                $interest = (int) $visits->get($u->id, 0);
                $conversions = (int) $won->get($u->id, 0);

                return [
                    'id' => $u->id,
                    'reference' => $u->reference,
                    'location' => $u->location?->name,
                    'sale_status' => $u->sale_status?->value,
                    'price' => $u->price,
                    'visits' => $interest,
                    'holds' => (int) $holds->get($u->id, 0),
                    'won' => $conversions,
                    'conversion' => $interest > 0 ? round($conversions / $interest * 100, 1) : 0.0,
                    'heat' => $interest,
                ];
            })
            ->sortByDesc('visits')
            ->values()
            ->all();
    }
}
