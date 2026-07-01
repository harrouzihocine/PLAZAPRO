<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Convert an active hold into a sale: flip the hold to converted and the unit
 * to sold.
 */
class ConvertReservation
{
    public function handle(Reservation $reservation): Reservation
    {
        abort_if($reservation->hold_status !== HoldStatus::Active, 422, 'This hold is not active.');

        return DB::transaction(function () use ($reservation) {
            $reservation->update(['hold_status' => HoldStatus::Converted->value]);
            $reservation->unit->update(['sale_status' => SaleStatus::Sold->value]);

            return $reservation->fresh();
        });
    }
}
