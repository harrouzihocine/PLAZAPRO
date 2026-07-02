<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

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
    public function handle(Unit $unit, array $data): Unit
    {
        $unit->update(Arr::only($data, [
            'reference', 'type_id', 'floor_id', 'area_sqm',
            'block', 'stack_floor', 'position', 'gtm_priority',
        ]));

        return $unit->fresh();
    }
}
