<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Events\BoxEdited;
use App\Modules\Inventory\Events\InventoryItemUpdated;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * A box's details were edited: drop a durable "box updated" record in every active
 * user's bell so the whole team sees inventory changes, not just the agent who
 * made them, and fire the live toast for anyone in the app. The body names the box
 * (with its type / location) and which fields changed. Runs on the queue.
 */
class AnnounceBoxEdited implements ShouldQueue
{
    /** Human labels for the editable box fields the bell can name as "changed". */
    private const FIELD_LABELS = [
        'reference' => 'reference',
        'type_id' => 'type',
        'price' => 'price',
        'sale_status' => 'status',
        'unit_id' => 'linked apartment',
    ];

    public function handle(BoxEdited $event): void
    {
        $box = $event->box->loadMissing(['location', 'type']);
        $recipients = User::query()->active()->where('is_active', true)->get();

        $context = collect([$box->type?->label, $box->location?->name])
            ->filter()
            ->implode(' · ');

        $changed = collect($event->changed)
            ->map(fn ($field) => self::FIELD_LABELS[$field] ?? null)
            ->filter()
            ->unique()
            ->implode(', ');

        $link = $box->location_id !== null ? '/inventory/locations/'.$box->location_id : null;

        if ($recipients->isNotEmpty()) {
            $body = collect(['Box '.$box->reference, $context, $changed !== '' ? $changed.' changed' : null])
                ->filter()
                ->implode(' — ');

            Notification::send($recipients, new DomainNotification(
                kind: 'box_updated',
                key: 'box_updated',
                params: ['box' => $box->reference, 'details' => $body],
                link: $link,
                subjectType: 'box',
                subjectId: $box->id,
            ));
        }

        // Live toast for anyone currently in the app (public channel).
        broadcast(new InventoryItemUpdated(
            type: 'box',
            id: $box->id,
            reference: (string) $box->reference,
            context: $context,
            changed: $changed,
            link: $link,
        ));
    }
}
