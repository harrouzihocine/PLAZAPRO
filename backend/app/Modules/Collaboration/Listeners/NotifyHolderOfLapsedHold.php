<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Events\ReservedLapsed;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A unit's Reserved deposit window lapsed: tell the agent who held it that it went
 * back on the market (their client did not finalize in time). Targeted to the
 * project's owner — the everyone-sees-it status change rides the live status
 * broadcast. Runs on the queue.
 */
class NotifyHolderOfLapsedHold implements ShouldQueue
{
    public function handle(ReservedLapsed $event): void
    {
        $project = ClientProject::query()->with('creator')->find($event->projectId);
        $agent = $project?->creator;

        if (! $agent instanceof User) {
            return;
        }

        $unit = $event->unit->loadMissing('location');
        $where = $unit->location?->name !== null ? ' at '.$unit->location->name : '';

        $agent->notify(new DomainNotification(
            kind: 'reserved_lapsed',
            key: 'reserved_lapsed',
            params: ['unit' => $unit->reference.$where],
            link: '/inventory/units/'.$unit->id,
            subjectType: 'unit',
            subjectId: $unit->id,
        ));
    }
}
