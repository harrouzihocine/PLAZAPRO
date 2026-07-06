<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled sweeper: every active interest hold whose expires_at has passed
 * is flipped to expired and its unit returned to the market — interested if
 * other projects still hold it as backups, else available (a Reserved deposit
 * lock held by a different project is left untouched). No-expiry deal holds are
 * never swept. Runs on the queue/scheduler. Returns the number of holds expired.
 */
class ExpireReservationHolds
{
    public function handle(): int
    {
        $expired = 0;

        Reservation::query()
            ->activeHold()
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($reservations) use (&$expired) {
                foreach ($reservations as $reservation) {
                    DB::transaction(function () use ($reservation) {
                        // Lock the unit so concurrent backups expiring together
                        // resolve the status once, not race to Available.
                        $unit = Unit::whereKey($reservation->unit_id)->lockForUpdate()->first();

                        $reservation->update(['hold_status' => HoldStatus::Expired->value]);

                        if ($unit === null || $unit->sale_status === SaleStatus::Sold) {
                            return;
                        }

                        // Back to the market — interested if OTHER projects
                        // still hold it as backups, else available (revertToMarket
                        // re-checks live holds). Never lift a Reserved deposit
                        // lock held by a different project.
                        $heldByAnother = $unit->sale_status === SaleStatus::Reserved
                            && (int) $unit->reserved_project_id !== (int) $reservation->client_project_id;
                        if (! $heldByAnother) {
                            $unit->revertToMarket();
                        }
                    });
                    $expired++;
                }
            });

        return $expired;
    }
}
