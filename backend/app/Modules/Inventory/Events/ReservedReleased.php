<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A unit's Reserved deposit lock was lifted WITHOUT a sale — the deal fell
 * through or the deposit window lapsed — so the reservation queue moved up.
 * Collaboration listens (NotifyNextInReservationQueue) to tell the project now
 * first in line that the unit is theirs to pursue. Dispatched AFTER the
 * releasing transaction commits, by every action that lifts the lock (the
 * sweeper and the deal close). Plain domain event, mirrors ReservedLapsed.
 */
class ReservedReleased
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Unit $unit,
        public int $formerProjectId,
    ) {}
}
