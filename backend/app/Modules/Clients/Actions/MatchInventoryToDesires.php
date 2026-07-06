<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Collection;

/**
 * The inverse of MatchDesireToInventory: given a unit, return the active desires
 * whose criteria it satisfies (wilaya / commune / type / floor / area / budget /
 * preferred sites), eager-loading each desire's client + assigned agent so the
 * caller can notify. Only criteria the client actually set are applied — the
 * mirror image of the forward matcher. A SOLD unit matches nothing; a reserved
 * or on-hold one still does (it can be taken as a backup / 2nd place).
 */
class MatchInventoryToDesires
{
    /**
     * @return Collection<int, Desire>
     */
    public function handle(Unit $unit): Collection
    {
        if ($unit->sale_status === SaleStatus::Sold) {
            return collect();
        }

        $unit->loadMissing('location');
        $wilayaId = $unit->location?->wilaya_id;
        $communeId = $unit->location?->commune_id;
        // Project type and contract type are project (location) attributes the unit inherits.
        $projectTypeId = $unit->location?->type_id;
        $contractTypeId = $unit->location?->contract_type_id;

        // A criterion the client set only matches when the unit actually has that
        // attribute; when the unit's value is null, only desires that left the
        // criterion open (null) match — so we skip the comparison branch entirely
        // (passing null into a <=/>= comparison is an illegal SQL combination).
        return Desire::query()
            ->active()
            ->with(['client.assignedAgent'])
            ->where(function ($q) use ($projectTypeId) {
                $q->whereNull('type_id');
                if ($projectTypeId !== null) {
                    $q->orWhere('type_id', $projectTypeId);
                }
            })
            ->where(function ($q) use ($unit) {
                $q->whereNull('room_number_id');
                if ($unit->room_number_id !== null) {
                    $q->orWhere('room_number_id', $unit->room_number_id);
                }
            })
            ->where(function ($q) use ($contractTypeId) {
                $q->whereNull('contract_type_id');
                if ($contractTypeId !== null) {
                    $q->orWhere('contract_type_id', $contractTypeId);
                }
            })
            ->where(function ($q) use ($unit) {
                $q->whereNull('floor_id');
                if ($unit->floor_id !== null) {
                    $q->orWhere('floor_id', $unit->floor_id);
                }
            })
            ->where(function ($q) use ($unit) {
                $q->whereNull('area_min');
                if ($unit->area_sqm !== null) {
                    $q->orWhere('area_min', '<=', $unit->area_sqm);
                }
            })
            ->where(function ($q) use ($unit) {
                $q->whereNull('area_max');
                if ($unit->area_sqm !== null) {
                    $q->orWhere('area_max', '>=', $unit->area_sqm);
                }
            })
            // Preferred sites: no rows = open to any site; otherwise the unit's
            // location must be one of them.
            ->where(function ($q) use ($unit) {
                $q->whereDoesntHave('locations')
                    ->orWhereHas('locations', fn ($l) => $l->where('locations.id', $unit->location_id));
            })
            ->where(function ($q) use ($unit) {
                $q->whereNull('budget_min');
                if ($unit->price !== null) {
                    $q->orWhere('budget_min', '<=', $unit->price);
                }
            })
            ->where(function ($q) use ($unit) {
                $q->whereNull('budget_max');
                if ($unit->price !== null) {
                    $q->orWhere('budget_max', '>=', $unit->price);
                }
            })
            ->where(function ($q) use ($wilayaId) {
                $q->whereNull('wilaya_id');
                if ($wilayaId !== null) {
                    $q->orWhere('wilaya_id', $wilayaId);
                }
            })
            ->where(function ($q) use ($communeId) {
                $q->whereNull('commune_id');
                if ($communeId !== null) {
                    $q->orWhere('commune_id', $communeId);
                }
            })
            ->get();
    }
}
