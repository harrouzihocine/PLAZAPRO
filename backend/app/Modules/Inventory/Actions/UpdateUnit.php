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
        'payment_methods_overridden', 'note',
    ];

    public function handle(Unit $unit, array $data): Unit
    {
        $unit->update(Arr::only($data, self::EDITABLE));

        $paymentMethodsChanged = $this->syncPaymentMethods($unit, $data);

        // Announce the edit to the whole team (Collaboration listens and drops a
        // "unit updated" bell for everyone) — but only when something actually
        // moved, so re-saving an unchanged form stays silent.
        $changed = array_values(array_intersect(self::EDITABLE, array_keys($unit->getChanges())));
        if ($paymentMethodsChanged && ! in_array('payment_methods', $changed, true)) {
            $changed[] = 'payment_methods';
        }

        if ($changed !== []) {
            UnitEdited::dispatch($unit, $changed);
        }

        return $unit->fresh();
    }

    /**
     * Apply the per-unit payment-method override: sync the unit's own set when
     * it overrides, otherwise clear it (fall back to inheriting the project's).
     * Skipped entirely when the field wasn't submitted, so a partial update
     * never touches it.
     *
     * @return bool whether the pivot actually changed
     */
    private function syncPaymentMethods(Unit $unit, array $data): bool
    {
        if (! array_key_exists('payment_methods_overridden', $data)) {
            return false;
        }

        $ids = $unit->payment_methods_overridden ? ($data['payment_method_ids'] ?? []) : [];
        $result = $unit->paymentMethods()->sync($ids);

        return $result['attached'] !== [] || $result['detached'] !== [];
    }
}
