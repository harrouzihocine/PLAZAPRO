<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Enums\ReminderState;
use App\Modules\Pipeline\Models\Reminder;

/**
 * Deliver due pending reminders. For the in_app channel this marks the reminder
 * sent — Phase 5 turns that into a Notification row for the action's assigned
 * agent. The email channel is deferred to Phase 7 infra. Runs every minute
 * (reminders:dispatch).
 */
class DispatchReminders
{
    public function handle(): int
    {
        $count = 0;

        Reminder::query()
            ->due()
            ->with('nextAction')
            ->chunkById(200, function ($reminders) use (&$count) {
                foreach ($reminders as $reminder) {
                    // Don't nudge about an action that was completed/cancelled in the
                    // window since the reminder was generated — cancel it instead.
                    $action = $reminder->nextAction;
                    if ($action && $action->state !== NextActionState::Pending) {
                        $reminder->update(['state' => ReminderState::Cancelled->value]);

                        continue;
                    }

                    $reminder->update([
                        'state' => ReminderState::Sent->value,
                        'sent_at' => now(),
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
