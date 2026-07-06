<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use App\Modules\Pipeline\Models\Visit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A visit was scheduled or (re)assigned to an agent. Fired by ScheduleVisit /
 * AssignVisit; Collaboration listens and notifies the assigned agent.
 *
 * `$isNew` distinguishes a freshly scheduled visit from a later reassignment:
 * only a brand-new visit alerts the wider audience (project contributors +
 * pipeline overseers) so an upcoming office visit gets organised. A reassignment
 * (AssignVisit) passes false and stays scoped to the agent / in-site followers.
 */
class VisitAssigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Visit $visit, public bool $isNew = true) {}
}
