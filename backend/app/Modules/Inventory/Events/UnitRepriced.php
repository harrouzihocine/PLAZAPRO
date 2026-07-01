<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A unit's price changed or it became available again (via CorrectUnit). Carries
 * the corrected version. Collaboration re-runs the reverse desire-match on the new
 * figures and alerts the agents whose clients now match.
 */
class UnitRepriced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Unit $unit) {}
}
