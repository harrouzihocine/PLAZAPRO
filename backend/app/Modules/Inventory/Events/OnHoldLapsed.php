<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A unit's On Hold deposit window lapsed without a sale — the sweeper returned it
 * to the market. Collaboration listens (NotifyHolderOfLapsedHold) to tell the
 * project that held it. Plain domain event: kept in Inventory so the module
 * stays free of any Collaboration import (mirrors UnitPublished).
 */
class OnHoldLapsed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Unit $unit,
        public int $projectId,
    ) {}
}
