<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Location;

/**
 * The exact reverse of MakeLocationUnavailable: bring a parked project back to
 * the market (is_available = true). Every unit inside it kept its own
 * sale_status while parked, so the project reappears in selectors and on the
 * public site exactly as it was.
 */
class MakeLocationAvailable
{
    public function handle(Location $location): Location
    {
        if (! $location->is_available) {
            $location->forceFill(['is_available' => true])->saveQuietly();
            $location->logActivity('make_available');
        }

        return $location;
    }
}
