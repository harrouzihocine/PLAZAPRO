<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Collection;

/**
 * The inverse of MatchDesireToInventory: given a unit, return the active desires
 * whose criteria it satisfies (wilayas / communes / types / floors / area / budget /
 * preferred sites), eager-loading each desire's client + assigned agent so the
 * caller can notify. Only criteria the client actually set are applied — the
 * mirror image of the forward matcher; every criterion is multi-valued (the
 * unit's value must be ANY of the picked ones). A SOLD unit matches nothing;
 * an interested or reserved one still does (it can be taken as a backup / 2nd
 * place).
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

        // A pivot criterion the client set (rows exist) only matches when the
        // unit actually has that attribute and it's one of the picked values;
        // no rows = no preference. Range criteria: when the unit's value is
        // null, only desires that left the bound open (null) match — we skip
        // the comparison branch entirely (null in a <=/>= is illegal SQL).
        $anyOf = function ($q, string $relation, string $ownerKey, ?int $value): void {
            $q->whereDoesntHave($relation);
            if ($value !== null) {
                $q->orWhereHas($relation, fn ($r) => $r->where($ownerKey, $value));
            }
        };

        return Desire::query()
            ->active()
            ->with(['client.assignedAgent'])
            ->where(fn ($q) => $anyOf($q, 'types', 'dynamic_list_items.id', $projectTypeId))
            ->where(fn ($q) => $anyOf($q, 'roomNumbers', 'dynamic_list_items.id', $unit->room_number_id))
            ->where(fn ($q) => $anyOf($q, 'contractTypes', 'dynamic_list_items.id', $contractTypeId))
            ->where(fn ($q) => $anyOf($q, 'floors', 'dynamic_list_items.id', $unit->floor_id))
            ->where(fn ($q) => $anyOf($q, 'wilayas', 'wilayas.id', $wilayaId))
            ->where(fn ($q) => $anyOf($q, 'communes', 'communes.id', $communeId))
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
            // Budget: EITHER finish price may fit the desire's window, but both
            // bounds must hold on the SAME price — so min and max are checked
            // together per price, not as independent clauses.
            ->where(function ($q) use ($unit) {
                $q->where(fn ($w) => $w->whereNull('budget_min')->whereNull('budget_max'));

                foreach ([$unit->price_semi_fini, $unit->price_fini] as $price) {
                    if ($price === null) {
                        continue;
                    }
                    $q->orWhere(fn ($w) => $w
                        ->where(fn ($m) => $m->whereNull('budget_min')->orWhere('budget_min', '<=', $price))
                        ->where(fn ($m) => $m->whereNull('budget_max')->orWhere('budget_max', '>=', $price)));
                }
            })
            ->get();
    }
}
