<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Move the back-to-market deadline of a unit this project holds with a
 * holding deposit — without recording another payment. Only the holder's
 * project can move its own deadline; the sweeper and the live status
 * broadcast pick the new moment up automatically (Unit model hook).
 */
class UpdateReservedWindow
{
    public function handle(ClientProject $project, Unit $unit, Carbon $reservedUntil): Unit
    {
        return DB::transaction(function () use ($project, $unit, $reservedUntil) {
            $fresh = Unit::whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            abort_if(
                $fresh->sale_status !== SaleStatus::Reserved
                    || (int) $fresh->reserved_project_id !== (int) $project->id,
                422,
                'This unit is not on hold for this client.',
            );

            $fresh->update(['reserved_expires_at' => $reservedUntil]);

            return $fresh;
        });
    }
}
