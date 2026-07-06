<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\UnitStatusChanged;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Drop a durable "unit status changed" record in every active user's bell when a
 * unit moves state (reserved / on hold / back on the market) — so the change
 * persists, not just the live toast. A SALE has its own richer notification
 * (AnnounceUnitSold) + celebration, so `sold` is skipped here; pure On Hold timer
 * changes (no state move) are skipped too. Runs on the queue.
 */
class AnnounceUnitStatusChange implements ShouldQueue
{
    /** Human wording per state the bell can announce. */
    private const LABELS = [
        'reserved' => 'reserved',
        'onhold' => 'on hold',
        'available' => 'back on the market',
    ];

    public function handle(UnitStatusChanged $event): void
    {
        if (! $event->saleStatusChanged) {
            return;
        }

        $status = $event->unit->sale_status?->value;

        // A sale is announced elsewhere (celebration + unit_sold bell record).
        if ($status === SaleStatus::Sold->value || ! isset(self::LABELS[$status])) {
            return;
        }

        $unit = $event->unit->loadMissing(['location', 'floor', 'roomNumber']);
        $recipients = User::query()->active()->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $details = collect([$unit->roomNumber?->label, $unit->floor?->label, $unit->location?->name])
            ->filter()
            ->implode(' · ');

        Notification::send($recipients, new DomainNotification(
            kind: 'unit_status',
            title: 'Unit '.$unit->reference.' — '.self::LABELS[$status],
            body: trim(ucfirst(self::LABELS[$status]).($details !== '' ? ' · '.$details : '')),
            link: '/inventory/units/'.$unit->id,
            subjectType: 'unit',
            subjectId: $unit->id,
        ));
    }
}
