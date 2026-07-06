<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Events\UnitEdited;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Arr;

/**
 * Ordinary spec edits (reference, type/floor, surface, stacking coords).
 * price and sale_status are NOT corrected here — those go through CorrectUnit
 * (HasVersions), and lifecycle transitions of sale_status go through the
 * reservation Actions.
 */
class UpdateUnit
{
    /** Editable spec fields — also the set whose changes are announced. */
    private const EDITABLE = [
        'reference', 'room_number_id', 'floor_id', 'area_sqm',
        'block', 'stack_floor', 'position', 'gtm_priority',
    ];

    public function handle(Unit $unit, array $data): Unit
    {
        $unit->update(Arr::only($data, self::EDITABLE));

        // Announce the edit to the whole team (Collaboration listens and drops a
        // "unit updated" bell for everyone) — but only when something actually
        // moved, so re-saving an unchanged form stays silent.
        $changed = array_values(array_intersect(self::EDITABLE, array_keys($unit->getChanges())));

        if ($changed !== []) {
            UnitEdited::dispatch($unit, $changed);
        }

        return $unit->fresh();
    }
}
