<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Events\VisitAssigned;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notify the agent a visit was assigned to. Runs on the queue.
 */
class SendVisitAssignedNotification implements ShouldQueue
{
    public function handle(VisitAssigned $event): void
    {
        $visit = $event->visit->loadMissing(['agent', 'client']);
        $agent = $visit->agent;

        if ($agent === null) {
            return;
        }

        $when = $visit->scheduled_at?->format('D d M, H:i');
        $client = $visit->client;
        $clientName = $client ? trim($client->first_name.' '.$client->last_name) : 'a client';

        $agent->notify(new DomainNotification(
            kind: 'visit_assigned',
            title: 'A visit was assigned to you',
            body: 'Visit with '.$clientName.($when ? ' on '.$when : '').'.',
            link: $client ? '/clients/'.$client->id : null,
            subjectType: $client ? 'client' : null,
            subjectId: $client?->id,
        ));
    }
}
