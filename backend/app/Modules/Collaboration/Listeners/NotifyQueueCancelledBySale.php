<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Inventory\Events\BackupHoldsCancelled;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A sale ended a unit's reservation queue: tell every queued project's
 * contributors that their client's reservation is cancelled — including the
 * place the client held ("was 2nd in line"), so the agent can explain the
 * situation honestly. The celebration bell (AnnounceUnitSold) goes to all
 * users; THIS one is the targeted "your client lost it" notice. Runs on the
 * queue.
 */
class NotifyQueueCancelledBySale implements ShouldQueue
{
    public function handle(BackupHoldsCancelled $event): void
    {
        $unit = $event->unit->loadMissing('location');
        $where = $unit->location?->name !== null ? ' at '.$unit->location->name : '';

        $projects = ClientProject::query()
            ->with('client')
            ->findMany(array_column($event->cancelled, 'client_project_id'))
            ->keyBy('id');

        foreach ($event->cancelled as $row) {
            $project = $projects->get($row['client_project_id']);

            if ($project === null) {
                continue;
            }

            [$link, $subjectType, $subjectId] = NotificationLink::forSubject($project);

            $notification = new DomainNotification(
                kind: 'reservation_cancelled',
                title: 'Reservation cancelled — unit sold',
                body: $unit->reference.$where.' was sold to another client. Your client\'s reservation (was #'.$row['position'].' in line) is cancelled.',
                link: $link,
                subjectType: $subjectType,
                subjectId: $subjectId,
            );

            foreach (User::query()->findMany($project->contributorIds()) as $recipient) {
                $recipient->notify($notification);
            }
        }
    }
}
