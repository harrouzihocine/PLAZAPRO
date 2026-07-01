<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Events\ReminderDue;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notify the next action's assignee that it is due. Runs on the queue. Skips
 * silently if the action was completed/cancelled or has no assignee — the same
 * guard DispatchReminders applies before firing.
 */
class SendDueReminderNotification implements ShouldQueue
{
    public function handle(ReminderDue $event): void
    {
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
            title: 'A follow-up is due',
            body: 'Your next action ('.$action->type->value.') is due now.',
            link: $link,
            subjectType: $subjectType,
            subjectId: $subjectId,
        ));
    }
}
