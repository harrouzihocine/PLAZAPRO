<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Place a 48-hour hold on an available unit: create the reservation with
 * expires_at = held_at + 48h and flip the unit to reserved — atomically. This is
 * the guide's canonical lifecycle example.
 */
class ReserveUnit
{
    public function handle(Unit $unit, array $data, User $agent): Reservation
    {
        return DB::transaction(function () use ($unit, $data, $agent) {
            // Lock the row so two agents can't both reserve the same unit.
            $fresh = Unit::whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            abort_if($fresh->sale_status !== SaleStatus::Available, 422, 'Unit is not available.');

            $heldAt = now();
            $reservation = Reservation::create([
                'unit_id' => $fresh->id,
                'client_project_id' => $data['client_project_id'] ?? null,
                'held_by' => $agent->id,
                'held_at' => $heldAt,
                'expires_at' => $heldAt->copy()->addHours(48),
                'hold_status' => HoldStatus::Active->value,
            ]);

            $fresh->update(['sale_status' => SaleStatus::Reserved->value]);

            return $reservation;
        });
    }
}
