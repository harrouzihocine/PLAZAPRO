<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Convert an active hold into a sale: flip the hold to converted and the unit
 * to sold. If the hold is linked to a deal (client_project), the deal is advanced
 * to `won` and stamped with the unit and its price — the Phase 2 ↔ 3 seam.
 */
class ConvertReservation
{
    public function handle(Reservation $reservation): Reservation
    {
        abort_if($reservation->hold_status !== HoldStatus::Active, 422, 'This hold is not active.');

        return DB::transaction(function () use ($reservation) {
            // Lock the unit so two concurrent conversions can't both sell it, and
            // re-assert its state under the lock.
            $unit = Unit::whereKey($reservation->unit_id)->lockForUpdate()->firstOrFail();

            abort_if($unit->sale_status === SaleStatus::Sold, 422, 'This unit is already sold.');
            abort_if(
                $unit->sale_status === SaleStatus::OnHold
                    && (int) $unit->onhold_project_id !== (int) $reservation->client_project_id,
                422,
                'This unit is on hold for another client.',
            );

            // This hold converts to the sale; every other live hold on the unit
            // (backups from other projects) loses it.
            $reservation->update(['hold_status' => HoldStatus::Converted->value]);
            Reservation::query()
                ->where('unit_id', $unit->id)
                ->where('hold_status', HoldStatus::Active->value)
                ->whereKeyNot($reservation->id)
                ->update(['hold_status' => HoldStatus::Released->value]);

            $unit->update([
                'sale_status' => SaleStatus::Sold->value,
                'onhold_expires_at' => null,
                'onhold_project_id' => null,
            ]);

            $project = $reservation->clientProject;
            if ($project && $project->isActive() && $project->stage !== ClientProjectStage::Won) {
                $project->update([
                    'stage' => ClientProjectStage::Won->value,
                    'unit_id' => $reservation->unit_id,
                    'total_price' => $unit->price,
                ]);
            }

            return $reservation->fresh();
        });
    }
}
