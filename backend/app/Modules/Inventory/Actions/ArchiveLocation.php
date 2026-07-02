<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Archive a project (location) and everything inside it — its units and boxes.
 * Archived rows drop out of the active() scope, so the project and its inventory
 * disappear from every normal list until ReactivateLocation brings them back.
 *
 * Reversible and non-destructive: unlike Remove, archiving is safe even over
 * reserved/sold units — sale_status is untouched and restored intact on
 * reactivate. Only active children are archived, so anything cancelled
 * beforehand stays cancelled.
 */
class ArchiveLocation
{
    public function handle(Location $location): Location
    {
        abort_unless($location->isActive(), 422, 'Only an active project can be archived.');

        return DB::transaction(function () use ($location) {
            $location->units()->active()->get()->each->archive();
            $location->boxes()->active()->get()->each->archive();

            return $location->archive();
        });
    }
}
