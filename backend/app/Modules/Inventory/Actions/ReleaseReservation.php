<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Release an active hold before it expires: flip the hold to released and return
 * the unit to available.
 */
class ReleaseReservation
{
    public function handle(Reservation $reservation): Reservation
    {
        abort_if($reservation->hold_status !== HoldStatus::Active, 422, 'This hold is not active.');

        return DB::transaction(function () use ($reservation) {
            $reservation->update(['hold_status' => HoldStatus::Released->value]);
            $reservation->unit->update(['sale_status' => SaleStatus::Available->value]);

            return $reservation->fresh();
        });
    }
}
