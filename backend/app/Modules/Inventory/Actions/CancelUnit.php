<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;

/**
 * Cancel (no-delete) a unit. An interested, reserved or sold unit can't be
 * cancelled — release or convert its holds first, so a live sale is never
 * silently discarded.
 */
class CancelUnit
{
    public function handle(Unit $unit, string $reason): Unit
    {
        abort_if(
            $unit->sale_status !== SaleStatus::Available,
            422,
            'Only available units can be cancelled; release or convert the sale first.',
        );

        return $unit->cancel($reason);
    }
}
