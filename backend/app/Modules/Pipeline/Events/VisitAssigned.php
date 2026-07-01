<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use App\Modules\Pipeline\Models\Visit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A visit was scheduled or (re)assigned to an agent. Fired by ScheduleVisit /
 * AssignVisit; Collaboration listens and notifies the assigned agent.
 */
class VisitAssigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Visit $visit) {}
}
