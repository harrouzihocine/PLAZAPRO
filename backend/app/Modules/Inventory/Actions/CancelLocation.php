<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Inventory\Models\Location;

/**
 * Cancel (no-delete) a location. Guarded: a location that still holds active
 * units or boxes can't be cancelled — cancel or move them first, so no inventory
 * is left pointing at a cancelled project.
 */
class CancelLocation
{
    public function handle(Location $location, string $reason): Location
    {
        $active = RecordStatus::Active->value;

        abort_if(
            $location->units()->where('status', $active)->exists()
                || $location->boxes()->where('status', $active)->exists(),
            422,
            'Cancel this project\'s active units and boxes before cancelling the project.',
        );

        return $location->cancel($reason);
    }
}
