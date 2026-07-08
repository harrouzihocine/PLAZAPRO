<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\ReservedLapsed;
use App\Modules\Inventory\Events\ReservedReleased;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled sweeper for Reserved units: every unit whose holding-deposit
 * window (reserved_expires_at) has passed without a sale is returned to the
 * market — interested if other projects still hold it as backups, else
 * available. The holder's own hold is released (their client did not finalize
 * in time) and the former holder is notified (ReservedLapsed → Collaboration).
 * Runs on the scheduler; returns the number of units freed.
 */
class ExpireReservedUnits
{
    public function handle(): int
    {
        $freed = 0;

        Unit::query()
            ->where('sale_status', SaleStatus::Reserved->value)
            ->whereNotNull('reserved_expires_at')
            ->where('reserved_expires_at', '<=', now())
            ->chunkById(200, function ($units) use (&$freed) {
                foreach ($units as $unit) {
                    $projectId = (int) $unit->reserved_project_id;

                    DB::transaction(function () use ($unit, $projectId) {
                        // Release the (former) holder's live hold — they let it lapse.
                        if ($projectId > 0) {
                            $unit->reservations()
                                ->where('client_project_id', $projectId)
                                ->where('hold_status', HoldStatus::Active->value)
                                ->update(['hold_status' => HoldStatus::Expired->value]);
                        }

                        // Back to interested (backups remain) or available (none)
                        // — revertToMarket clears the deposit lock and fires the
                        // live status broadcast.
                        $unit->revertToMarket();
                    });

                    if ($projectId > 0) {
                        ReservedLapsed::dispatch($unit, $projectId);
                        // The queue moves up: tell the next project in line the
                        // unit is theirs to pursue (no-op when nobody queues).
                        ReservedReleased::dispatch($unit, $projectId);
                    }

                    $freed++;
                }
            });

        return $freed;
    }
}
