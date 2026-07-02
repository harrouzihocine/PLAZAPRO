<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * The exact reverse of ArchiveLocation: bring an archived project and the units
 * and boxes archived with it back to active. Only rows currently in the
 * `archived` state are revived — inventory cancelled independently before the
 * archive stays cancelled.
 */
class ReactivateLocation
{
    public function handle(Location $location): Location
    {
        abort_unless($location->isArchived(), 422, 'Only an archived project can be reactivated.');

        return DB::transaction(function () use ($location) {
            $location->units()->archived()->get()->each->reactivate();
            $location->boxes()->archived()->get()->each->reactivate();

            return $location->reactivate();
        });
    }
}
