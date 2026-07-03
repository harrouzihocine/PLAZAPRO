<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Match a client's desire to inventory: return the **available** units that fit
 * the desire's wilaya / commune / type / floor / area / budget / preferred sites,
 * ranked by closeness (best first). Only criteria the client actually set are
 * applied. This is a key rule to test.
 */
class MatchDesireToInventory
{
    /**
     * @return Collection<int, Unit>
     */
    public function handle(Desire $desire): Collection
    {
        $preferredLocationIds = $desire->locations()->pluck('locations.id');

        $units = Unit::query()
            ->active()
            ->with(['location', 'type', 'floor'])
            ->where('sale_status', SaleStatus::Available->value)
            ->when($desire->type_id, fn ($q) => $q->where('type_id', $desire->type_id))
            ->when($desire->floor_id, fn ($q) => $q->where('floor_id', $desire->floor_id))
            ->when($desire->area_min !== null, fn ($q) => $q->where('area_sqm', '>=', $desire->area_min))
            ->when($desire->area_max !== null, fn ($q) => $q->where('area_sqm', '<=', $desire->area_max))
            ->when($desire->budget_min !== null, fn ($q) => $q->where('price', '>=', $desire->budget_min))
            ->when($desire->budget_max !== null, fn ($q) => $q->where('price', '<=', $desire->budget_max))
            ->when($preferredLocationIds->isNotEmpty(), fn ($q) => $q->whereIn('location_id', $preferredLocationIds))
            ->when($desire->wilaya_id, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('wilaya_id', $desire->wilaya_id)))
            ->when($desire->commune_id, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('commune_id', $desire->commune_id)))
            ->get();

        // Rank by closeness (lower score = better): distance from the budget the
        // client indicated.
        return $units
            ->sortBy(fn (Unit $unit) => $this->score($unit, $desire))
            ->values();
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
