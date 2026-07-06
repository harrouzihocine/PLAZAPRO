<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Events\BoxAnnounced;
use App\Modules\Inventory\Events\BoxPublished;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * A new box (parking / storage) was added: drop a durable "new box added" record
 * in every active user's bell (click → its location) and fire the ephemeral live
 * toast. Boxes have no detail page, so the bell deep-links to the owning location.
 * Runs on the queue.
 */
class AnnounceNewBox implements ShouldQueue
{
    public function handle(BoxPublished $event): void
    {
        $box = $event->box->loadMissing(['location', 'type']);

        $recipients = User::query()->active()->where('is_active', true)->get();

        if ($recipients->isNotEmpty()) {
            $body = collect([$box->reference, $box->type?->label, $box->location?->name])
                ->filter()
                ->implode(' · ');

            Notification::send($recipients, new DomainNotification(
                kind: 'box_published',
                title: 'New box added',
                body: $body,
                link: $box->location_id !== null ? '/inventory/locations/'.$box->location_id : null,
                subjectType: 'box',
                subjectId: $box->id,
            ));
        }

        // Live toast for anyone currently in the app (public channel).
        broadcast(new BoxAnnounced($box));
    }
}
