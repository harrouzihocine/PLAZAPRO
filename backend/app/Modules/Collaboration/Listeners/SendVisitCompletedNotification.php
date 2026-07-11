<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Pipeline\Events\VisitCompleted;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A visit's log task was completed (in-site or office). The completer is often
 * a dispatched field agent filling the log for a client they don't own, so the
 * people who need to know it's done are the ones who didn't do it:
 *  - the client's owner (assigned sales agent, else whoever created the client);
 *  - the dispatchers (visits.dispatch holders) who run the in-site board.
 * The completer is always skipped — they just did it. Runs on the queue;
 * delivery is DB + broadcast + FCM so recipients see it live.
 */
class SendVisitCompletedNotification implements ShouldQueue
{
    public function handle(VisitCompleted $event): void
    {
        $visit = $event->visit->loadMissing(['agent', 'client', 'clientProject', 'unit.location']);
        $client = $visit->client;

        if ($client === null) {
            return;
        }

        $when = $visit->visited_at?->format('D d M, H:i');

        // Deep-link to the project workspace when the visit belongs to one —
        // that page holds the log; the client file is the project-less fallback.
        [$link, $subjectType, $subjectId] = NotificationLink::forSubject($visit->clientProject ?? $client);

        // Locale-neutral extras (time, reference, site); the sentence itself is
        // a per-recipient template in lang/xx/notifications.php.
        $extra = implode('', array_filter([
            $when ? ' · '.$when : null,
            $visit->unit ? ' · '.$visit->unit->reference : null,
            $visit->unit?->location?->name ? ' · '.$visit->unit->location->name : null,
        ]));

        $notification = new DomainNotification(
            kind: 'visit_completed',
            key: 'visit_completed',
            params: [
                'type' => '@notifications.type.'.$visit->type->value,
                'agent' => $visit->agent?->name ?? '—',
                'client' => $client->full_name ?: 'a client',
                'extra' => $extra,
            ],
            link: $link,
            subjectType: $subjectType,
            subjectId: $subjectId,
        );

        // Owner (assigned agent, else creator) + dispatchers, minus the completer.
        $ownerId = $client->assigned_agent_id ?? $client->created_by;

        $recipients = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $event->actorId)
            ->where(fn ($q) => $q
                ->when($ownerId, fn ($qq) => $qq->where('id', $ownerId))
                ->orWhereHas('role.permissions', fn ($p) => $p->where('slug', 'visits.dispatch')))
            ->get();

        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }
}
