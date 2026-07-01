<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use App\Modules\Pipeline\Models\Reminder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A due reminder has come up. Fired by DispatchReminders as it delivers the
 * per-minute batch; Collaboration listens and notifies the action's assignee.
 * Kept in Pipeline so the module stays free of any Collaboration import.
 */
class ReminderDue
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Reminder $reminder) {}
}
