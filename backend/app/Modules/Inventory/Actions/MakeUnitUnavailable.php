<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;

/**
 * Park a unit off the market: the promoteur withholds it from selling. Only a
 * free (available) unit can be parked — a held or sold unit carries a live sale
 * that must be released/reversed first, so `unavailable` never competes with a
 * real hold for the sale_status scalar.
 *
 * A plain update: the model's booted() hook fires UnitStatusChanged, which both
 * repaints open views live (public "announcements" channel) and drops the
 * "made unavailable" bell (AnnounceUnitStatusChange). Reverse with MakeUnitAvailable.
 */
class MakeUnitUnavailable
{
    public function handle(Unit $unit): Unit
    {
        abort_unless(
            $unit->sale_status === SaleStatus::Available,
            422,
            'Only an available unit can be made unavailable — release its holds or reverse the sale first.',
        );

        $unit->update(['sale_status' => SaleStatus::Unavailable->value]);

        return $unit;
    }
}
