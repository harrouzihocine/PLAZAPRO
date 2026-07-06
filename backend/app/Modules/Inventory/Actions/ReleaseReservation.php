<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Release an active 48h interest hold before it expires: flip the hold to
 * released and return the unit to the market — interested if other projects
 * still hold it as backups, else available. This is the manual "give up my
 * hold" action; a no-expiry hold backing an open deal or a holding deposit is
 * NOT releasable here (only closing the deal / the deposit lapsing releases it).
 */
class ReleaseReservation
{
    public function handle(Reservation $reservation): Reservation
    {
        abort_if($reservation->hold_status !== HoldStatus::Active, 422, 'This hold is not active.');
        abort_if(
            $reservation->expires_at === null,
            422,
            'This hold backs an open deal — close the deal to release it.',
        );

        return DB::transaction(function () use ($reservation) {
            // Lock the unit so the status resolves cleanly against concurrent
            // holds / closes on the same unit.
            $unit = Unit::whereKey($reservation->unit_id)->lockForUpdate()->firstOrFail();

            $reservation->update(['hold_status' => HoldStatus::Released->value]);

            // Back to the market, but never lift a Reserved deposit lock held
            // by a DIFFERENT project just because a backup gave up — the same
            // guard CloseDealUnit::lose() uses.
            $heldByAnother = $unit->sale_status === SaleStatus::Reserved
                && (int) $unit->reserved_project_id !== (int) $reservation->client_project_id;
            if (! $heldByAnother) {
                $unit->revertToMarket();
            }

            return $reservation->fresh();
        });
    }
}
