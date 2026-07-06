<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Clients\Actions\MatchInventoryToDesires;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Events\UnitPublished;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * When a unit is added or repriced, alert the agents whose clients' desires it
 * matches (reverse desire-match). One notification per agent per unit event,
 * listing the matching clients — so only agents with a genuine match are pinged,
 * never a blanket broadcast. Runs on the queue.
 */
class NotifyAgentsOfMatchingUnit implements ShouldQueue
{
    public function __construct(private MatchInventoryToDesires $matcher) {}

    public function handle(object $event): void
    {
        $unit = $event->unit->loadMissing(['floor', 'roomNumber']);
        $isNew = $event instanceof UnitPublished;

        $matches = $this->matcher->handle($unit);

        // Group by owning agent so an agent with several matching clients is
        // notified once, with all of them listed.
        $byAgent = $matches
            ->filter(fn ($desire) => $desire->client?->assigned_agent_id !== null)
            ->groupBy(fn ($desire) => $desire->client->assigned_agent_id);

        foreach ($byAgent as $desires) {
            $agent = $desires->first()->client->assignedAgent;
            if ($agent === null) {
                continue;
            }

            $clients = $desires
                ->map(fn ($desire) => $desire->client->full_name)
                ->unique()
                ->implode(', ');

            $unitDetails = collect([$unit->roomNumber?->label, $unit->floor?->label])
                ->filter()
                ->implode(' · ');

            $agent->notify(new DomainNotification(
                kind: 'unit_match',
                title: $isNew ? 'New unit matches a client' : 'A matching unit was repriced',
                body: 'Unit '.$unit->reference.($unitDetails !== '' ? ' ('.$unitDetails.')' : '').' fits: '.$clients.'.',
                link: '/inventory/units/'.$unit->id,
                subjectType: 'unit',
                subjectId: $unit->id,
            ));
        }
    }
}
