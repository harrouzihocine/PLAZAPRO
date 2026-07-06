<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\OnHoldLapsed;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled sweeper for On Hold units: every unit whose holding-deposit
 * window (onhold_expires_at) has passed without a sale is returned to the market
 * — reserved if other projects still hold it as backups, else available. The
 * holder's own hold is released (their client did not finalize in time) and the
 * former holder is notified (OnHoldLapsed → Collaboration). Runs on the
 * scheduler; returns the number of units freed.
 */
class ExpireOnHoldUnits
{
    public function handle(): int
    {
        $freed = 0;

        Unit::query()
            ->where('sale_status', SaleStatus::OnHold->value)
            ->whereNotNull('onhold_expires_at')
            ->where('onhold_expires_at', '<=', now())
            ->chunkById(200, function ($units) use (&$freed) {
                foreach ($units as $unit) {
                    $projectId = (int) $unit->onhold_project_id;

                    DB::transaction(function () use ($unit, $projectId) {
                        // Release the (former) holder's live hold — they let it lapse.
                        if ($projectId > 0) {
                            $unit->reservations()
                                ->where('client_project_id', $projectId)
                                ->where('hold_status', HoldStatus::Active->value)
                                ->update(['hold_status' => HoldStatus::Expired->value]);
                        }

                        // Back to reserved (backups remain) or available (none) —
                        // revertToMarket clears the On Hold lock and fires the live
                        // status broadcast.
                        $unit->revertToMarket();
                    });

                    if ($projectId > 0) {
                        OnHoldLapsed::dispatch($unit, $projectId);
                    }

                    $freed++;
                }
            });

        return $freed;
    }
}
