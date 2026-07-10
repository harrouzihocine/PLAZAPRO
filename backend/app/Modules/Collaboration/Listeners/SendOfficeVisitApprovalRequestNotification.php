<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Pipeline\Events\OfficeVisitApprovalRequested;
use App\Modules\Pipeline\Support\OfficeVisitWindow;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An office-visit plan overshot the office_visit_max_days window: notify every
 * visits.dispatch holder so one of them approves / denies / reschedules it
 * from the Office Visits Program page. Runs on the queue. Mirrors
 * SendDispatchRequestNotification — same audience, different desk.
 */
class SendOfficeVisitApprovalRequestNotification implements ShouldQueue
{
    // The event fires inside CreateNextAction/CorrectNextAction transactions;
    // without this a fast worker could grab the job before the next_actions
    // row is committed and fail restoring the model (no dispatcher notified).
    public bool $afterCommit = true;

    public function handle(OfficeVisitApprovalRequested $event): void
    {
        // Morph-aware load: the subject is a project (its client nests) or a
        // bare client (a project-less qualifying visit) — never assume either.
        $action = $event->nextAction->load([
            'approvalRequestedBy:id,name',
            'subject' => fn (MorphTo $m) => $m->morphWith([ClientProject::class => ['client']]),
        ]);

        $subject = $action->subject;
        $clientName = ($subject instanceof ClientProject ? $subject->client : $subject)
            ?->full_name ?: 'a client';

        [, $subjectType, $subjectId] = NotificationLink::forSubject($subject);

        $when = $action->due_at;
        $date = $when === null ? '' : $when->format(
            $when->hour === 0 && $when->minute === 0 ? 'D d M' : 'D d M, H:i',
        );

        // role.permissions eager-loaded: can() → hasPermission() then reads the
        // loaded collection instead of one query per user (N+1).
        $dispatchers = User::query()
            ->active()
            ->where('is_active', true)
            ->with('role.permissions')
            ->get()
            ->filter(fn (User $u) => $u->can('visits.dispatch'));

        foreach ($dispatchers as $dispatcher) {
            $dispatcher->notify(new DomainNotification(
                kind: 'office_visit_approval',
                key: 'office_visit_approval',
                params: [
                    'user' => $action->approvalRequestedBy?->name ?? 'An agent',
                    'client' => $clientName,
                    'date' => $date,
                    'days' => OfficeVisitWindow::maxDays(),
                ],
                link: '/oversight/office-program',
                subjectType: $subjectType,
                subjectId: $subjectId,
            ));
        }
    }
}
