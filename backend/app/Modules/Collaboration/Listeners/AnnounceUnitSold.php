<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Events\UnitSold;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * A unit was sold: drop a durable "unit sold" record in every active user's bell
 * (the celebration overlay is the live broadcast; this is the lasting record,
 * and it also reaches the agents whose backup reservations the sale released).
 * Runs on the queue.
 */
class AnnounceUnitSold implements ShouldQueue
{
    public function handle(UnitSold $event): void
    {
        $recipients = User::query()->active()->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $parts = array_filter([
            $event->reference,
            $event->details['room_number'] ?? null,
            $event->details['floor'] ?? null,
            $event->locationName,
            $event->price !== null ? $event->price.' DA' : null,
        ]);

        Notification::send($recipients, new DomainNotification(
            kind: 'unit_sold',
            title: 'Unit sold 🎉',
            body: implode(' · ', $parts),
            link: '/inventory/units/'.$event->unitId,
            subjectType: 'unit',
            subjectId: $event->unitId,
        ));
    }
}
