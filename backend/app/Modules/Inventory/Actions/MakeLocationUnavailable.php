<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Location;

/**
 * Park a whole project off the market: set is_available = false. The project
 * vanishes from every internal selector (property pickers, cross-project sweeps,
 * desire matching) and shows greyed on the public site, but stays in the
 * inventory management list (tagged) with every unit's own sale_status intact.
 *
 * Always allowed — parking touches nothing but the veil flag, so reserved / sold
 * units inside it keep their state and reappear untouched when the project is
 * reactivated with MakeLocationAvailable. Reversible; distinct from archive
 * (which hides from management too) and cancel (which removes).
 */
class MakeLocationUnavailable
{
    public function handle(Location $location): Location
    {
        if ($location->is_available) {
            // saveQuietly + an explicit named log (like archive/cancel) so the
            // audit reads "made unavailable", not a generic "update".
            $location->forceFill(['is_available' => false])->saveQuietly();
            $location->logActivity('make_unavailable');
        }

        return $location;
    }
}
