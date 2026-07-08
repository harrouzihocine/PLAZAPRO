<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Pipeline\Events\InSiteDispatchRequested;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An in-site plan needs a field agent: notify every visits.dispatch holder so
 * one of them assigns it from the weekly board. Runs on the queue.
 */
class SendDispatchRequestNotification implements ShouldQueue
{
    public function handle(InSiteDispatchRequested $event): void
    {
        $action = $event->nextAction->loadMissing('subject.client');
        $subject = $action->subject;
        $clientName = $subject?->client?->full_name ?? $subject?->full_name ?? 'a client';

        [, $subjectType, $subjectId] = NotificationLink::forSubject($subject);

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
                kind: 'dispatch_request',
                key: 'dispatch_request',
                params: ['client' => $clientName, 'date' => (string) $action->due_at?->format('D d M')],
                link: '/dispatch',
                subjectType: $subjectType,
                subjectId: $subjectId,
            ));
        }
    }
}
