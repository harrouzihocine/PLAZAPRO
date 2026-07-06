<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Place an interest hold on a unit: create the hold record and, if the unit was
 * free, flip it to Interested — atomically. Interest holds are MULTI-PROJECT:
 * several client projects can hold the same unit as backups ("2nd place"), so
 * the only thing that blocks a hold is a finalized sale. A unit already
 * Interested or Reserved (deposit paid) keeps its (stronger) status — the new
 * backup simply bumps the derived "Interested N" count. The hold window comes
 * from the interest_hold_hours app setting; a hold backing an open deal never
 * expires (expires_at null) — only closing the deal releases or converts it.
 */
class ReserveUnit
{
    public function handle(Unit $unit, array $data, User $agent): Reservation
    {
        return DB::transaction(function () use ($unit, $data, $agent) {
            // Lock the row so concurrent reservations resolve the status cleanly.
            $fresh = Unit::whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            abort_if($fresh->sale_status === SaleStatus::Sold, 422, 'Unit is already sold.');

            // Backups are ACROSS projects — one project never holds the same unit
            // twice (a second deal on it would just duplicate its own hold).
            if (($data['client_project_id'] ?? null) !== null) {
                $alreadyHeld = Reservation::query()
                    ->where('unit_id', $fresh->id)
                    ->where('client_project_id', $data['client_project_id'])
                    ->where('hold_status', HoldStatus::Active->value)
                    ->exists();
                abort_if($alreadyHeld, 422, 'This project already holds this unit.');
            }

            $heldAt = now();
            $reservation = Reservation::create([
                'unit_id' => $fresh->id,
                'client_project_id' => $data['client_project_id'] ?? null,
                'held_by' => $agent->id,
                'held_at' => $heldAt,
                'expires_at' => ($data['no_expiry'] ?? false)
                    ? null
                    : $heldAt->copy()->addHours(AppSetting::integer('interest_hold_hours', 48)),
                'hold_status' => HoldStatus::Active->value,
            ]);

            // Available → interested; interested / reserved stay as-is (stronger
            // state wins; the backup only raises the count).
            if ($fresh->sale_status === SaleStatus::Available) {
                $fresh->update(['sale_status' => SaleStatus::Interested->value]);
            }

            return $reservation;
        });
    }
}
