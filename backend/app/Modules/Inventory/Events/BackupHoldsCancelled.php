<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A sale ended a unit's reservation queue: every OTHER project's live hold was
 * released the moment the unit sold. Carries each cancelled project with the
 * queue position it held at that moment, so Collaboration
 * (NotifyQueueCancelledBySale) can tell each team "your client was 2nd in
 * line — the unit is gone". Dispatched AFTER the selling transaction commits
 * (mirrors UnitSold). Plain domain event: Inventory stays free of any
 * Collaboration import.
 */
class BackupHoldsCancelled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<array{client_project_id: int, position: int}>  $cancelled
     */
    public function __construct(
        public Unit $unit,
        public array $cancelled,
    ) {}
}
