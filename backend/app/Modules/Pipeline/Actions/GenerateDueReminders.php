<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\ReminderChannel;
use App\Modules\Pipeline\Enums\ReminderState;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Reminder;
use App\Modules\Pipeline\Models\Task;

/**
 * Generate reminders for pending next actions AND open tasks that are
 * due/overdue and don't yet have a live reminder. The reminder targets the
 * assigned agent. Runs on a schedule (actions:mark-overdue).
 */
class GenerateDueReminders
{
    public function handle(): int
    {
        return $this->forNextActions() + $this->forTasks();
    }

    private function forNextActions(): int
    {
        $count = 0;

        NextAction::query()
            ->active()
            ->overdue()
            // Unassigned plans sit in the dispatch pool — there is nobody to
            // remind yet, and a reminder "sent" to nobody would consume the
            // one-live-reminder slot forever (whereDoesntHave below). The
            // dispatchers were already notified (InSiteDispatchRequested);
            // the reminder generates once an agent is assigned.
            ->whereNotNull('assigned_to')
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

    /**
     * Same sweep for the tasks board: an open task past its due date gets one
     * live reminder for its assignee (tasks always have one — no pool case).
     */
    private function forTasks(): int
    {
        $count = 0;

        Task::query()
            ->active()
            ->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->whereDoesntHave('reminders', fn ($q) => $q->whereIn('state', [
                ReminderState::Pending->value,
                ReminderState::Sent->value,
            ]))
            ->chunkById(200, function ($tasks) use (&$count) {
                foreach ($tasks as $task) {
                    Reminder::create([
                        'task_id' => $task->id,
                        'remind_at' => $task->due_at,
                        'channel' => ReminderChannel::InApp->value,
                        'state' => ReminderState::Pending->value,
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
