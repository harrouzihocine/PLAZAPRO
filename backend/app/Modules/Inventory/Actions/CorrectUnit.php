<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\UnitEdited;
use App\Modules\Inventory\Events\UnitRepriced;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Arr;

/**
 * Correct an immutable field (price and/or sale_status) via HasVersions: the
 * original row is cancelled and a linked replacement is inserted, so the change
 * is auditable and the old value is never overwritten. Returns the new version.
 */
class CorrectUnit
{
    public function handle(Unit $unit, array $data): Unit
    {
        $changes = Arr::only($data, ['price', 'sale_status']);
        $reason = $data['reason'] ?? 'Correction';

        $originalPrice = (string) $unit->price;
        $originalStatus = $unit->sale_status;

        $replacement = $unit->supersedeWith($changes, $reason);

        $priceChanged = (string) $replacement->price !== $originalPrice;
        $statusChanged = $replacement->sale_status !== $originalStatus;

        // Announce the correction to the whole team (Collaboration drops a "unit
        // updated" bell for everyone), naming the field(s) that moved.
        $edited = array_values(array_filter([
            $priceChanged ? 'price' : null,
            $statusChanged ? 'sale_status' : null,
        ]));

        if ($edited !== []) {
            UnitEdited::dispatch($replacement, $edited);
        }

        // Re-run the reverse desire-match only when the change could create new
        // matches: the price moved, or the unit became available again.
        $becameAvailable = $replacement->sale_status === SaleStatus::Available
            && $originalStatus !== SaleStatus::Available;

        if ($priceChanged || $becameAvailable) {
            UnitRepriced::dispatch($replacement);
        }

        return $replacement;
    }
}
