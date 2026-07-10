<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Events\ReminderDue;
use App\Modules\Pipeline\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notify the assignee that their next action (or board task) is due. Runs on
 * the queue. Skips silently if the work was completed/cancelled or has no
 * assignee — the same guard DispatchReminders applies before firing.
 */
class SendDueReminderNotification implements ShouldQueue
{
    public function handle(ReminderDue $event): void
    {
        if ($event->reminder->task_id) {
            $this->notifyTaskAssignee($event);

            return;
        }

        $action = $event->reminder->nextAction()->with(['assignedTo', 'subject'])->first();

        if ($action === null || $action->state !== NextActionState::Pending) {
            return;
        }

        $user = $action->assignedTo;
        if ($user === null) {
            return;
        }

        [$link, $subjectType, $subjectId] = NotificationLink::forSubject($action->subject);

        $user->notify(new DomainNotification(
            kind: 'reminder',
            key: 'reminder',
            params: ['type' => '@notifications.reminder_type.'.$action->type->value],
            link: $link,
            subjectType: $subjectType,
            subjectId: $subjectId,
        ));
    }

    private function notifyTaskAssignee(ReminderDue $event): void
    {
        $task = $event->reminder->task()->with('assignedTo')->first();

        if ($task === null || $task->state !== TaskState::Open || $task->assignedTo === null) {
            return;
        }

        $task->assignedTo->notify(new DomainNotification(
            kind: 'reminder',
            key: 'task_reminder',
            params: ['title' => $task->title],
            link: '/tasks',
            subjectType: Task::class,
            subjectId: $task->id,
        ));
    }
}
