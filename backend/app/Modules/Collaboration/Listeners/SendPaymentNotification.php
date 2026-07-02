<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Payments\Events\VersementRecorded;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notify a deal's owning agent that a payment was recorded/corrected on it. Runs
 * on the queue. Silent when the deal's client has no assigned agent.
 */
class SendPaymentNotification implements ShouldQueue
{
    public function handle(VersementRecorded $event): void
    {
        $versement = $event->versement->loadMissing('clientProject.client.assignedAgent');
        $client = $versement->clientProject?->client;
        $agent = $client?->assignedAgent;

        if ($agent === null) {
            return;
        }

        $clientName = $client->full_name;

        $agent->notify(new DomainNotification(
            kind: 'payment',
            title: 'Payment recorded',
            body: 'A payment of '.$versement->amount.' was recorded on '.$clientName."'s deal.",
            link: '/clients/'.$client->id,
            subjectType: 'client',
            subjectId: $client->id,
        ));
    }
}
