<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Events\InventoryItemUpdated;
use App\Modules\Inventory\Events\UnitEdited;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * A unit's details were edited (specs, or a price/status correction): drop a
 * durable "unit updated" record in every active user's bell so the whole team
 * sees inventory changes — whether made from the unit page or a location's unit
 * tab — not just the agent who made them. The body names the unit (with its rooms
 * / floor / location) and which fields changed. Runs on the queue.
 */
class AnnounceUnitEdited implements ShouldQueue
{
    /** Human labels for the editable fields the bell can name as "changed". */
    private const FIELD_LABELS = [
        'reference' => 'reference',
        'room_number_id' => 'rooms',
        'floor_id' => 'floor',
        'area_sqm' => 'area',
        'price_semi_fini' => 'semi-fini price',
        'price_fini' => 'fini price',
        'sale_status' => 'status',
        'block' => 'block',
        'stack_floor' => 'stacking floor',
        'position' => 'position',
        'gtm_priority' => 'priority',
    ];

    public function handle(UnitEdited $event): void
    {
        $unit = $event->unit->loadMissing(['location', 'floor', 'roomNumber']);
        $recipients = User::query()->active()->where('is_active', true)->get();

        $context = collect([$unit->roomNumber?->label, $unit->floor?->label, $unit->location?->name])
            ->filter()
            ->implode(' · ');

        $changed = collect($event->changed)
            ->map(fn ($field) => self::FIELD_LABELS[$field] ?? null)
            ->filter()
            ->unique()
            ->implode(', ');

        $link = '/inventory/units/'.$unit->id;

        if ($recipients->isNotEmpty()) {
            $body = collect([$context, $changed !== '' ? $changed.' changed' : null])
                ->filter()
                ->implode(' — ');

            Notification::send($recipients, new DomainNotification(
                kind: 'unit_updated',
                key: 'unit_updated',
                params: ['unit' => $unit->reference, 'details' => $body],
                link: $link,
                subjectType: 'unit',
                subjectId: $unit->id,
            ));
        }

        // Live toast for anyone currently in the app (public channel).
        broadcast(new InventoryItemUpdated(
            type: 'unit',
            id: $unit->id,
            reference: (string) $unit->reference,
            context: $context,
            changed: $changed,
            link: $link,
        ));
    }
}
