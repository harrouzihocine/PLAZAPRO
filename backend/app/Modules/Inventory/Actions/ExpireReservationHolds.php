<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled sweeper: every active hold whose expires_at has passed is flipped
 * to expired and its unit returned to available. Runs on the queue/scheduler.
 * Returns the number of holds expired.
 */
class ExpireReservationHolds
{
    public function handle(): int
    {
        $expired = 0;

        Reservation::query()
            ->activeHold()
            ->where('expires_at', '<=', now())
            ->with('unit')
            ->chunkById(200, function ($reservations) use (&$expired) {
                foreach ($reservations as $reservation) {
                    DB::transaction(function () use ($reservation) {
                        $reservation->update(['hold_status' => HoldStatus::Expired->value]);
                        // Only return the unit if it is still reserved by this hold.
                        if ($reservation->unit && $reservation->unit->sale_status === SaleStatus::Reserved) {
                            $reservation->unit->update(['sale_status' => SaleStatus::Available->value]);
                        }
                    });
                    $expired++;
                }
            });

        return $expired;
    }
}
