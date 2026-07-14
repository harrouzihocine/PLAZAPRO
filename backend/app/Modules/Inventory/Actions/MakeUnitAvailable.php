<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\UnitRepriced;
use App\Modules\Inventory\Models\Unit;

/**
 * Bring a parked (unavailable) unit back to the market. The exact reverse of
 * MakeUnitUnavailable — only a unit currently unavailable can be reactivated;
 * everything else is a live sale state the reservation lifecycle owns.
 *
 * The status move rides the model's booted() hook (UnitStatusChanged → live
 * repaint + "back on the market" bell). We also dispatch UnitRepriced so the
 * reverse desire-match re-runs and waiting clients hear about the freed unit,
 * exactly like CorrectUnit's becameAvailable path.
 */
class MakeUnitAvailable
{
    public function handle(Unit $unit): Unit
    {
        abort_unless(
            $unit->sale_status === SaleStatus::Unavailable,
            422,
            'Only a unit currently marked unavailable can be brought back to the market.',
        );

        $unit->update(['sale_status' => SaleStatus::Available->value]);

        UnitRepriced::dispatch($unit);

        return $unit;
    }
}
