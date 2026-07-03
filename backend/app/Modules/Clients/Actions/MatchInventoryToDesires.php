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
 * mirror image of the forward matcher. An unavailable unit matches nothing.
 */
class MatchInventoryToDesires
{
    /**
     * @return Collection<int, Desire>
     */
    public function handle(Unit $unit): Collection
    {
        if ($unit->sale_status !== SaleStatus::Available) {
            return collect();
        }

        $unit->loadMissing('location');
        $wilayaId = $unit->location?->wilaya_id;
        $communeId = $unit->location?->commune_id;

        // A criterion the client set only matches when the unit actually has that
        // attribute; when the unit's value is null, only desires that left the
        // criterion open (null) match — so we skip the comparison branch entirely
        // (passing null into a <=/>= comparison is an illegal SQL combination).
        return Desire::query()
            ->active()
            ->with(['client.assignedAgent'])
            ->where(function ($q) use ($unit) {
                $q->whereNull('type_id');
                if ($unit->type_id !== null) {
                    $q->orWhere('type_id', $unit->type_id);
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
