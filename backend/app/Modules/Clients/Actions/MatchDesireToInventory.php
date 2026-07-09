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
 * taken as a backup / 2nd place) that fit the desire's wilayas / communes / types /
 * floors / area / budget / preferred sites, ranked by closeness (best first). Only
 * criteria the client actually set are applied; every criterion is multi-valued
 * (a unit passes when its value is ANY of the picked ones). This is a key rule to test.
 */
class MatchDesireToInventory
{
    /**
     * @return Collection<int, Unit>
     */
    /**
     * The criteria pivots, uniformly: pivot table + the unit/location column the
     * picked values are matched against. desire_list_items carries the four
     * dynamic-list criteria (`field` discriminator); "no rows = no preference".
     */
    private const PIVOT_CRITERIA = [
        ['table' => 'desire_wilayas', 'key' => 'wilaya_id', 'field' => null, 'column' => 'locations.wilaya_id'],
        ['table' => 'desire_communes', 'key' => 'commune_id', 'field' => null, 'column' => 'locations.commune_id'],
        ['table' => 'desire_list_items', 'key' => 'item_id', 'field' => 'type', 'column' => 'locations.type_id'],
        ['table' => 'desire_list_items', 'key' => 'item_id', 'field' => 'contract_type', 'column' => 'locations.contract_type_id'],
        ['table' => 'desire_list_items', 'key' => 'item_id', 'field' => 'room_number', 'column' => 'units.room_number_id'],
        ['table' => 'desire_list_items', 'key' => 'item_id', 'field' => 'floor', 'column' => 'units.floor_id'],
    ];

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
        // ranks and we fetch only the leaders. A unit quotes up to two finish
        // prices — rank by whichever sits closest to the budget target (a
        // missing price falls back to an unreachably-far distance).
        $min = $desire->budget_min !== null ? (float) $desire->budget_min : null;
        $max = $desire->budget_max !== null ? (float) $desire->budget_max : null;
        $target = match (true) {
            $min !== null && $max !== null => ($min + $max) / 2,
            $max !== null => $max,
            $min !== null => $min,
            default => null,
        };
        if ($target !== null) {
            $q->orderByRaw(
                'LEAST(COALESCE(ABS(price_semi_fini - ?), 1e18), COALESCE(ABS(price_fini - ?), 1e18))',
                [$target, $target],
            );
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
                ->whereRaw('(desires.area_min IS NULL OR units.area_sqm >= desires.area_min)')
                ->whereRaw('(desires.area_max IS NULL OR units.area_sqm <= desires.area_max)')
                // Budget window: EITHER finish price may fit, but both bounds
                // must hold on the SAME price (semi-fini under the min plus fini
                // over the max fits neither offer).
                ->whereRaw(
                    '((desires.budget_min IS NULL AND desires.budget_max IS NULL)'
                    .' OR (units.price_semi_fini IS NOT NULL'
                    .'     AND (desires.budget_min IS NULL OR units.price_semi_fini >= desires.budget_min)'
                    .'     AND (desires.budget_max IS NULL OR units.price_semi_fini <= desires.budget_max))'
                    .' OR (units.price_fini IS NOT NULL'
                    .'     AND (desires.budget_min IS NULL OR units.price_fini >= desires.budget_min)'
                    .'     AND (desires.budget_max IS NULL OR units.price_fini <= desires.budget_max)))'
                )
                // Preferred sites: no pivot rows = any site; otherwise the unit's
                // project must be one of them (the whereIn on plucked ids in query()).
                ->whereRaw(
                    '(NOT EXISTS (SELECT 1 FROM desire_locations dl WHERE dl.desire_id = desires.id)'
                    .' OR EXISTS (SELECT 1 FROM desire_locations dl WHERE dl.desire_id = desires.id AND dl.location_id = units.location_id))'
                );

            // The multi-valued criteria, same shape as preferred sites: no pivot
            // rows = no preference; otherwise the unit's value must be picked.
            foreach (self::PIVOT_CRITERIA as $c) {
                $scope = $c['field'] !== null ? " AND p.field = '{$c['field']}'" : '';
                $q->whereRaw(
                    "(NOT EXISTS (SELECT 1 FROM {$c['table']} p WHERE p.desire_id = desires.id{$scope})"
                    ." OR EXISTS (SELECT 1 FROM {$c['table']} p WHERE p.desire_id = desires.id{$scope} AND p.{$c['key']} = {$c['column']}))"
                );
            }
        });
    }

    /** The still-purchasable units matching a desire's criteria — unranked, unhydrated. */
    private function query(Desire $desire): Builder
    {
        $preferredLocationIds = $desire->locations()->pluck('locations.id');
        $wilayaIds = $desire->wilayas()->pluck('wilayas.id');
        $communeIds = $desire->communes()->pluck('communes.id');
        $typeIds = $desire->types()->pluck('dynamic_list_items.id');
        $roomNumberIds = $desire->roomNumbers()->pluck('dynamic_list_items.id');
        $contractTypeIds = $desire->contractTypes()->pluck('dynamic_list_items.id');
        $floorIds = $desire->floors()->pluck('dynamic_list_items.id');

        return Unit::query()
            ->active()
            ->where('sale_status', '!=', SaleStatus::Sold->value)
            // Project type lives on the unit's project (location), not the unit.
            ->when($typeIds->isNotEmpty(), fn ($q) => $q->whereHas('location', fn ($l) => $l->whereIn('type_id', $typeIds)))
            ->when($roomNumberIds->isNotEmpty(), fn ($q) => $q->whereIn('room_number_id', $roomNumberIds))
            ->when($floorIds->isNotEmpty(), fn ($q) => $q->whereIn('floor_id', $floorIds))
            ->when($desire->area_min !== null, fn ($q) => $q->where('area_sqm', '>=', $desire->area_min))
            ->when($desire->area_max !== null, fn ($q) => $q->where('area_sqm', '<=', $desire->area_max))
            // Budget window: EITHER finish price may fit, both bounds on the SAME price.
            ->when(
                $desire->budget_min !== null || $desire->budget_max !== null,
                fn ($q) => $q->where(function ($outer) use ($desire) {
                    foreach (['price_semi_fini', 'price_fini'] as $column) {
                        $outer->orWhere(fn ($price) => $price
                            ->whereNotNull($column)
                            ->when($desire->budget_min !== null, fn ($w) => $w->where($column, '>=', $desire->budget_min))
                            ->when($desire->budget_max !== null, fn ($w) => $w->where($column, '<=', $desire->budget_max)));
                    }
                }),
            )
            ->when($preferredLocationIds->isNotEmpty(), fn ($q) => $q->whereIn('location_id', $preferredLocationIds))
            ->when($wilayaIds->isNotEmpty(), fn ($q) => $q->whereHas('location', fn ($l) => $l->whereIn('wilaya_id', $wilayaIds)))
            ->when($communeIds->isNotEmpty(), fn ($q) => $q->whereHas('location', fn ($l) => $l->whereIn('commune_id', $communeIds)))
            // Contract type lives on the unit's project (location), not the unit.
            ->when($contractTypeIds->isNotEmpty(), fn ($q) => $q->whereHas('location', fn ($l) => $l->whereIn('contract_type_id', $contractTypeIds)));
    }

    private function score(Unit $unit, Desire $desire): float
    {
        $min = $desire->budget_min !== null ? (float) $desire->budget_min : null;
        $max = $desire->budget_max !== null ? (float) $desire->budget_max : null;

        $target = match (true) {
            $min !== null && $max !== null => ($min + $max) / 2,
            $max !== null => $max,
            $min !== null => $min,
            default => null,
        };

        if ($target === null) {
            return 0.0;
        }

        // Two finish prices — the closer one is the unit's distance (mirrors
        // handleTop's LEAST(...) SQL ranking).
        return collect([$unit->price_semi_fini, $unit->price_fini])
            ->filter(fn ($price) => $price !== null)
            ->map(fn ($price) => abs((float) $price - $target))
            ->min() ?? 0.0;
    }
}
