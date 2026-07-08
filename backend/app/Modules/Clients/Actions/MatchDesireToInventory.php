<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Match a client's desire to inventory: return the still-purchasable units (not
 * yet sold — available, interested or reserved, since an interested/reserved unit can be
 * taken as a backup / 2nd place) that fit the desire's wilaya / commune / type /
 * floor / area / budget / preferred sites, ranked by closeness (best first). Only
 * criteria the client actually set are applied. This is a key rule to test.
 */
class MatchDesireToInventory
{
    /**
     * @return Collection<int, Unit>
     */
    public function handle(Desire $desire): Collection
    {
        $units = $this->query($desire)
            ->with([
                'location.type', 'location.wilaya', 'location.commune', 'location.contractType',
                'floor', 'roomNumber',
            ])
            ->get();

        // Rank by closeness (lower score = better): distance from the budget the
        // client indicated.
        return $units
            ->sortBy(fn (Unit $unit) => $this->score($unit, $desire))
            ->values();
    }

    /**
     * The page-sized form of handle(): rank in SQL and hydrate only the top
     * $limit units, plus the full match count (for "+N more not shown"). A
     * loose desire matches most of the units table, and the board pays that
     * hydration per row without this — handle() stays for single-desire
     * callers that want the whole list.
     *
     * @return array{units: Collection<int, Unit>, total: int}
     */
    public function handleTop(Desire $desire, int $limit): array
    {
        $total = $this->query($desire)->count();

        $q = $this->query($desire)->with([
            'location.type', 'location.wilaya', 'location.commune', 'location.contractType',
            'floor', 'roomNumber',
        ]);

        // Same closeness score() computes, expressed in SQL so the database
        // ranks and we fetch only the leaders.
        $min = $desire->budget_min !== null ? (float) $desire->budget_min : null;
        $max = $desire->budget_max !== null ? (float) $desire->budget_max : null;
        if ($min !== null && $max !== null) {
            $q->orderByRaw('ABS(price - ?)', [($min + $max) / 2]);
        } elseif ($max !== null) {
            $q->orderByRaw('ABS(? - price)', [$max]);
        } elseif ($min !== null) {
            $q->orderByRaw('ABS(price - ?)', [$min]);
        }

        return ['units' => $q->orderBy('id')->limit($limit)->get(), 'total' => $total];
    }

    /**
     * Whether a desire has at least one match — same criteria as handle(), but no
     * hydration/eager-loads/ranking. Cheap enough to run per waiting desire (the
     * sidebar "Matches" badge counts how many desires have at least one hit).
     */
    public function exists(Desire $desire): bool
    {
        return $this->query($desire)->exists();
    }

    /**
     * Constrain a `desires` query to desires with at least one matching unit —
     * the whole board's "has a match" test as ONE correlated EXISTS instead of a
     * query per desire (the per-row form melts down past a few hundred waiting
     * clients). Mirrors query() exactly: `desires.x IS NULL` is "no preference",
     * and the LEFT JOIN keeps whereHas semantics for location criteria (a
     * location-less unit passes only when the criterion is unset).
     */
    public function whereHasMatch(Builder $desires): Builder
    {
        return $desires->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('units')
                ->leftJoin('locations', 'locations.id', '=', 'units.location_id')
                ->where('units.status', RecordStatus::Active->value)
                ->where('units.sale_status', '!=', SaleStatus::Sold->value)
                ->whereRaw('(desires.type_id IS NULL OR locations.type_id = desires.type_id)')
                ->whereRaw('(desires.room_number_id IS NULL OR units.room_number_id = desires.room_number_id)')
                ->whereRaw('(desires.floor_id IS NULL OR units.floor_id = desires.floor_id)')
                ->whereRaw('(desires.area_min IS NULL OR units.area_sqm >= desires.area_min)')
                ->whereRaw('(desires.area_max IS NULL OR units.area_sqm <= desires.area_max)')
                ->whereRaw('(desires.budget_min IS NULL OR units.price >= desires.budget_min)')
                ->whereRaw('(desires.budget_max IS NULL OR units.price <= desires.budget_max)')
                ->whereRaw('(desires.wilaya_id IS NULL OR locations.wilaya_id = desires.wilaya_id)')
                ->whereRaw('(desires.commune_id IS NULL OR locations.commune_id = desires.commune_id)')
                ->whereRaw('(desires.contract_type_id IS NULL OR locations.contract_type_id = desires.contract_type_id)')
                // Preferred sites: no pivot rows = any site; otherwise the unit's
                // project must be one of them (the whereIn on plucked ids in query()).
                ->whereRaw(
                    '(NOT EXISTS (SELECT 1 FROM desire_locations dl WHERE dl.desire_id = desires.id)'
                    .' OR EXISTS (SELECT 1 FROM desire_locations dl WHERE dl.desire_id = desires.id AND dl.location_id = units.location_id))'
                );
        });
    }

    /** The still-purchasable units matching a desire's criteria — unranked, unhydrated. */
    private function query(Desire $desire): Builder
    {
        $preferredLocationIds = $desire->locations()->pluck('locations.id');

        return Unit::query()
            ->active()
            ->where('sale_status', '!=', SaleStatus::Sold->value)
            // Project type lives on the unit's project (location), not the unit.
            ->when($desire->type_id, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('type_id', $desire->type_id)))
            ->when($desire->room_number_id, fn ($q) => $q->where('room_number_id', $desire->room_number_id))
            ->when($desire->floor_id, fn ($q) => $q->where('floor_id', $desire->floor_id))
            ->when($desire->area_min !== null, fn ($q) => $q->where('area_sqm', '>=', $desire->area_min))
            ->when($desire->area_max !== null, fn ($q) => $q->where('area_sqm', '<=', $desire->area_max))
            ->when($desire->budget_min !== null, fn ($q) => $q->where('price', '>=', $desire->budget_min))
            ->when($desire->budget_max !== null, fn ($q) => $q->where('price', '<=', $desire->budget_max))
            ->when($preferredLocationIds->isNotEmpty(), fn ($q) => $q->whereIn('location_id', $preferredLocationIds))
            ->when($desire->wilaya_id, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('wilaya_id', $desire->wilaya_id)))
            ->when($desire->commune_id, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('commune_id', $desire->commune_id)))
            // Contract type lives on the unit's project (location), not the unit.
            ->when($desire->contract_type_id, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('contract_type_id', $desire->contract_type_id)));
    }

    private function score(Unit $unit, Desire $desire): float
    {
        $price = (float) $unit->price;
        $min = $desire->budget_min !== null ? (float) $desire->budget_min : null;
        $max = $desire->budget_max !== null ? (float) $desire->budget_max : null;

        $score = 0.0;
        if ($min !== null && $max !== null) {
            $score += abs($price - ($min + $max) / 2);
        } elseif ($max !== null) {
            $score += abs($max - $price);
        } elseif ($min !== null) {
            $score += abs($price - $min);
        }

        return $score;
    }
}
