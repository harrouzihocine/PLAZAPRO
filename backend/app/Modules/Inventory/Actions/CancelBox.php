<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;

/**
 * Cancel (no-delete) a box. An interested or sold box can't be cancelled — settle
 * the sale first.
 */
class CancelBox
{
    public function handle(Box $box, string $reason): Box
    {
        abort_if(
            $box->sale_status !== SaleStatus::Available,
            422,
            'Only available boxes can be cancelled.',
        );

        return $box->cancel($reason);
    }
}
