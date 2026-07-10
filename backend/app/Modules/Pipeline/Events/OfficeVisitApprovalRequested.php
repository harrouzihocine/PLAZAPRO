<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use App\Modules\Pipeline\Models\NextAction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An office-visit plan landed beyond the office_visit_max_days window and
 * waits for a manager's verdict. Collaboration listens and notifies every
 * visits.dispatch holder so one of them approves / denies / reschedules it
 * from the Office Visits Program page.
 */
class OfficeVisitApprovalRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public NextAction $nextAction) {}
}
