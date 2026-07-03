<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\ReminderChannel;
use App\Modules\Pipeline\Enums\ReminderState;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Reminder;

/**
 * Generate reminders for pending next actions that are due/overdue and don't yet
 * have a live reminder. The reminder targets the action's assigned agent (via
 * next_action.assigned_to). Runs on a schedule (actions:mark-overdue).
 */
class GenerateDueReminders
{
    public function handle(): int
    {
        $count = 0;

        NextAction::query()
            ->active()
            ->overdue()
            ->whereDoesntHave('reminders', fn ($q) => $q->whereIn('state', [
                ReminderState::Pending->value,
                ReminderState::Sent->value,
            ]))
            ->chunkById(200, function ($actions) use (&$count) {
                foreach ($actions as $action) {
                    Reminder::create([
                        'next_action_id' => $action->id,
                        'remind_at' => $action->due_at,
                        'channel' => ReminderChannel::InApp->value,
                        'state' => ReminderState::Pending->value,
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
