<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Enums\ReminderState;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Events\ReminderDue;
use App\Modules\Pipeline\Models\Reminder;

/**
 * Deliver due pending reminders: mark the reminder sent and raise a ReminderDue
 * event so Collaboration notifies the assignee (next action or task). The email
 * channel is deferred to Phase 7 infra. Runs every minute (reminders:dispatch).
 */
class DispatchReminders
{
    public function handle(): int
    {
        $count = 0;

        Reminder::query()
            ->due()
            ->with(['nextAction', 'task'])
            ->chunkById(200, function ($reminders) use (&$count) {
                foreach ($reminders as $reminder) {
                    // Don't nudge about work that was completed/cancelled in the
                    // window since the reminder was generated — cancel it instead.
                    $action = $reminder->nextAction;
                    if ($action && $action->state !== NextActionState::Pending) {
                        $reminder->update(['state' => ReminderState::Cancelled->value]);

                        continue;
                    }
                    $task = $reminder->task;
                    if ($task && $task->state !== TaskState::Open) {
                        $reminder->update(['state' => ReminderState::Cancelled->value]);

                        continue;
                    }

                    $reminder->update([
                        'state' => ReminderState::Sent->value,
                        'sent_at' => now(),
                    ]);

                    // Turn the reminder into an in-app notification for the assignee.
                    ReminderDue::dispatch($reminder);
                    $count++;
                }
            });

        return $count;
    }
}
