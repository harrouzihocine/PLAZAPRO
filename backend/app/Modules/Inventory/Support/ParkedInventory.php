<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Settings\Models\User;

/**
 * THE rule for who may see inventory parked off the market (a unit made
 * `unavailable`, or a whole project the promoteur veiled with
 * `is_available = false`).
 *
 * Managers — anyone holding units.manage or locations.manage, the same grants
 * that park/un-park — keep seeing parked stock in the management lists
 * (tagged) so they can bring it back. Everyone else must not find it
 * ANYWHERE: browse tables, pickers, search, exports. Direct references
 * (unit/location detail pages, open deals, visits) still resolve — parking
 * freezes marketing, it does not erase in-flight history.
 */
class ParkedInventory
{
    public static function visibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // The grants are checked per list row elsewhere in the request —
        // pin them in memory so the two can() calls stay query-free.
        $user->role?->loadMissing('permissions');

        return $user->canAny(['units.manage', 'locations.manage']);
    }
}
