<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
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
            $reservation->update(['hold_status' => HoldStatus::Converted->value]);
            $reservation->unit->update(['sale_status' => SaleStatus::Sold->value]);

            $project = $reservation->clientProject;
            if ($project && $project->isActive() && $project->stage !== ClientProjectStage::Won) {
                $project->update([
                    'stage' => ClientProjectStage::Won->value,
                    'unit_id' => $reservation->unit_id,
                    'total_price' => $reservation->unit->price,
                ]);
            }

            return $reservation->fresh();
        });
    }
}
