<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Events\VisitDeclined;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A field agent declined an assigned in-site visit. The plan is already back
 * in the pool (the standard dispatch_request fired with it); this companion
 * tells the dispatchers WHO bounced it and WHY, so the re-assignment starts
 * with context instead of a phone call.
 */
class SendVisitDeclinedNotification implements ShouldQueue
{
    public function handle(VisitDeclined $event): void
    {
        $visit = $event->visit->loadMissing(['agent', 'client', 'unit.location']);

        $extra = implode('', array_filter([
            $visit->unit ? ' · '.$visit->unit->reference : null,
            $visit->unit?->location?->name ? ' · '.$visit->unit->location->name : null,
        ]));

        $notification = new DomainNotification(
            kind: 'visit_declined',
            key: 'visit_declined',
            params: [
                'agent' => $visit->agent?->name ?? '—',
                'client' => $visit->client?->full_name ?? 'a client',
                'reason' => $event->reason,
                'extra' => $extra,
            ],
            link: '/dispatch',
            subjectType: 'visit',
            subjectId: $visit->id,
        );

        $dispatchers = User::query()
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'visits.dispatch'))
            ->where('id', '!=', $event->agentId)
            ->get();

        foreach ($dispatchers as $dispatcher) {
            $dispatcher->notify($notification);
        }
    }
}
