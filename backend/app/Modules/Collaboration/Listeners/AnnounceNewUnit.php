<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Events\UnitAnnounced;
use App\Modules\Inventory\Events\UnitPublished;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * A new unit was added: drop a durable "new unit added" record in every active
 * user's bell (click → the unit) and fire the ephemeral public toast. Runs on
 * the queue. Reverse desire-matching is a SEPARATE listener on the same event.
 */
class AnnounceNewUnit implements ShouldQueue
{
    public function handle(UnitPublished $event): void
    {
        $unit = $event->unit->loadMissing(['location', 'floor', 'roomNumber']);

        $recipients = User::query()->active()->where('is_active', true)->get();

        if ($recipients->isNotEmpty()) {
            $body = collect([$unit->reference, $unit->roomNumber?->label, $unit->floor?->label, $unit->location?->name])
                ->filter()
                ->implode(' · ');

            Notification::send($recipients, new DomainNotification(
                kind: 'unit_published',
                key: 'unit_published',
                params: ['details' => $body],
                link: '/inventory/units/'.$unit->id,
                subjectType: 'unit',
                subjectId: $unit->id,
            ));
        }

        // Live toast for anyone currently in the app (public channel).
        broadcast(new UnitAnnounced($unit));
    }
}
