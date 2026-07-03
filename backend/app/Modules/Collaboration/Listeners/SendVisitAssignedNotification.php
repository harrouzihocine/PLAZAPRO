<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Pipeline\Events\VisitAssigned;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notify the agent a visit was assigned to — and, for an in-site visit, the
 * project's contributors too, so everyone following the project knows the
 * field agent is set. Runs on the queue.
 */
class SendVisitAssignedNotification implements ShouldQueue
{
    public function handle(VisitAssigned $event): void
    {
        $visit = $event->visit->loadMissing(['agent', 'client', 'clientProject', 'unit.location']);
        $agent = $visit->agent;

        if ($agent === null) {
            return;
        }

        $when = $visit->scheduled_at?->format('D d M, H:i');
        $client = $visit->client;
        $clientName = $client?->full_name ?: 'a client';

        // Deep-link to the project workspace when the visit belongs to one —
        // that page holds the log, the property and the map; the client file
        // is only the fallback for project-less (qualifying) visits.
        [$link, $subjectType, $subjectId] = NotificationLink::forSubject($visit->clientProject ?? $client);

        $details = array_filter([
            ucfirst(str_replace('_', '-', $visit->type->value)).' visit with '.$clientName,
            $when ? 'on '.$when : null,
            $visit->unit ? 'at '.$visit->unit->reference : null,
            $visit->unit?->location?->name,
        ]);

        $agent->notify(new DomainNotification(
            kind: 'visit_assigned',
            title: 'A visit was assigned to you',
            body: implode(' · ', $details).'.',
            link: $link,
            subjectType: $subjectType,
            subjectId: $subjectId,
        ));

        // In-site: tell the project's contributors the field agent is set.
        $project = $visit->clientProject;
        if ($visit->type->value !== 'in_site' || $project === null) {
            return;
        }

        $contributors = User::query()
            ->findMany($project->contributorIds())
            ->reject(fn ($u) => $u->id === $agent->id);

        foreach ($contributors as $contributor) {
            $contributor->notify(new DomainNotification(
                kind: 'visit_agent_assigned',
                title: 'In-site agent assigned',
                body: $agent->name.' will handle the '.implode(' · ', $details).'.',
                link: $link,
                subjectType: $subjectType,
                subjectId: $subjectId,
            ));
        }
    }
}
