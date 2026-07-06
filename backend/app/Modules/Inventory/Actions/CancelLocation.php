<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Remove (no-delete) a project (location) and everything inside it. The project,
 * its units and its boxes are all marked cancelled — rows and history kept and
 * audited, never hard-deleted.
 *
 * Terminal, unlike ArchiveLocation. Guarded so a live sale is never silently
 * discarded: removal is refused while any unit or box is interested, reserved or sold —
 * release or convert those first. Once every non-cancelled unit/box is available,
 * they are cancelled along with the project in one transaction.
 */
class CancelLocation
{
    public function handle(Location $location, string $reason): Location
    {
        $cancelled = RecordStatus::Cancelled->value;
        $available = SaleStatus::Available->value;

        abort_if(
            $location->units()->where('status', '!=', $cancelled)->where('sale_status', '!=', $available)->exists()
                || $location->boxes()->where('status', '!=', $cancelled)->where('sale_status', '!=', $available)->exists(),
            422,
            "Release or convert this project's interested, reserved or sold units and boxes before removing it.",
        );

        return DB::transaction(function () use ($location, $reason, $cancelled) {
            $childReason = 'Parent project removed';

            $location->units()->where('status', '!=', $cancelled)->get()->each->cancel($childReason);
            $location->boxes()->where('status', '!=', $cancelled)->get()->each->cancel($childReason);

            return $location->cancel($reason);
        });
    }
}
