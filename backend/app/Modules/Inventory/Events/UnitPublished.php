<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new unit was added to inventory. Collaboration listens and alerts the agents
 * whose clients' desires it matches (reverse desire-match). Kept in Inventory so
 * the module stays free of any Collaboration import.
 */
class UnitPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Unit $unit) {}
}
