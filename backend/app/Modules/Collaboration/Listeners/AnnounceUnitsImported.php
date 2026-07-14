<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Events\UnitsImported;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * A CSV unit import landed: ONE summary bell for the whole team ("N added, M
 * updated, K archived by X") instead of a per-unit announcement flood — the
 * archived count covers the snapshot sync (units the file dropped). Runs on the queue.
 */
class AnnounceUnitsImported implements ShouldQueue
{
    public function handle(UnitsImported $event): void
    {
        $recipients = User::query()->active()->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new DomainNotification(
            kind: 'units_imported',
            key: 'units_imported',
            params: [
                'user' => $event->user->name,
                'created' => $event->created,
                'updated' => $event->updated,
                'archived' => $event->archived,
            ],
            link: '/inventory/units',
        ));
    }
}
