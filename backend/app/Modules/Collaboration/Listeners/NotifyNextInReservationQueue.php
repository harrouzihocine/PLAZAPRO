<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\ReservedReleased;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A unit's Reserved deposit lock was lifted without a sale — the queue moved
 * up. Tell the project now FIRST in line (all its contributors) that the unit
 * is back within reach, so its agent calls the client who was told "you are
 * 2nd — if the 1st doesn't buy, it goes to you". The former holder's own
 * notice is NotifyHolderOfLapsedHold; the everyone-sees-it repaint rides the
 * live status broadcast. Runs on the queue.
 */
class NotifyNextInReservationQueue implements ShouldQueue
{
    public function handle(ReservedReleased $event): void
    {
        $unit = $event->unit->fresh(['location']);

        // Sold (or gone) in the meantime — the cancellation flow owns that story.
        if ($unit === null || $unit->sale_status === SaleStatus::Sold) {
            return;
        }

        // The release already ended the former holder's hold, so the head of
        // the live queue IS the promoted project (the filter is belt-and-braces).
        $next = $unit->reservationQueue()
            ->first(fn ($hold) => (int) $hold->client_project_id !== $event->formerProjectId);

        if ($next === null) {
            return;
        }

        $project = ClientProject::query()->with('client')->find($next->client_project_id);

        if ($project === null || ! $project->isActive()) {
            return;
        }

        [$link, $subjectType, $subjectId] = NotificationLink::forSubject($project);

        $where = $unit->location?->name !== null ? ' at '.$unit->location->name : '';
        $notification = new DomainNotification(
            kind: 'reservation_next',
            title: 'Your client is now first in line',
            body: 'The reservation on '.$unit->reference.$where.' was released — your client is next. Call them before the unit moves.',
            link: $link,
            subjectType: $subjectType,
            subjectId: $subjectId,
        );

        foreach (User::query()->findMany($project->contributorIds()) as $recipient) {
            $recipient->notify($notification);
        }
    }
}
