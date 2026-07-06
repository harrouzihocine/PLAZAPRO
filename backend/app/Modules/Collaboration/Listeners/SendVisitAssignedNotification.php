<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Pipeline\Events\VisitAssigned;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

/**
 * Notify the agent a visit was assigned to, plus the right wider audience:
 *  - in-site visit  → the project's contributors, so everyone following the
 *                     project knows which field agent is set (on assign/reassign);
 *  - new office visit → the project's contributors AND the pipeline overseers
 *                     (oversight.pipeline holders), so an upcoming office visit
 *                     gets picked up and organised in real time.
 * Runs on the queue; delivery is DB + broadcast, so recipients see it live.
 */
class SendVisitAssignedNotification implements ShouldQueue
{
    public function handle(VisitAssigned $event): void
    {
        $visit = $event->visit->loadMissing(['agent', 'client', 'clientProject', 'unit.location']);
        $agent = $visit->agent;

        if ($agent === null) {
            return;
        }

        $when = $visit->scheduled_at?->format('D d M, H:i');
        $client = $visit->client;
        $clientName = $client?->full_name ?: 'a client';

        // Deep-link to the project workspace when the visit belongs to one —
        // that page holds the log, the property and the map; the client file
        // is only the fallback for project-less (qualifying) visits.
        [$link, $subjectType, $subjectId] = NotificationLink::forSubject($visit->clientProject ?? $client);

        $details = array_filter([
            ucfirst(str_replace('_', '-', $visit->type->value)).' visit with '.$clientName,
            $when ? 'on '.$when : null,
            $visit->unit ? 'at '.$visit->unit->reference : null,
            $visit->unit?->location?->name,
        ]);
        $summary = implode(' · ', $details);

        $agent->notify(new DomainNotification(
            kind: 'visit_assigned',
            title: 'A visit was assigned to you',
            body: $summary.'.',
            link: $link,
            subjectType: $subjectType,
            subjectId: $subjectId,
        ));

        $project = $visit->clientProject;

        // In-site: tell the project's contributors the field agent is set.
        if ($visit->type->value === 'in_site' && $project !== null) {
            $this->fanOut($project->contributorIds(), $agent->id, new DomainNotification(
                kind: 'visit_agent_assigned',
                title: 'In-site agent assigned',
                body: $agent->name.' will handle the '.$summary.'.',
                link: $link,
                subjectType: $subjectType,
                subjectId: $subjectId,
            ));

            return;
        }

        // New office visit: alert the project's contributors and the pipeline
        // overseers so the upcoming visit gets organised. Reassignments (isNew
        // = false) stay scoped to the agent above.
        if ($visit->type->value === 'office' && $event->isNew) {
            $recipientIds = $this->pipelineOverseerIds()
                ->merge($project?->contributorIds() ?? [])
                ->unique();

            $this->fanOut($recipientIds, $agent->id, new DomainNotification(
                kind: 'office_visit_scheduled',
                title: 'Upcoming office visit',
                body: $summary.' — with '.$agent->name.'.',
                link: $link,
                subjectType: $subjectType,
                subjectId: $subjectId,
            ));
        }
    }

    /** Active users holding the Pipeline oversight permission. */
    private function pipelineOverseerIds(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'oversight.pipeline'))
            ->pluck('id');
    }

    /**
     * Send one notification to each recipient id, skipping the assigned agent
     * (already notified directly) and any blanks.
     *
     * @param  Collection<int, int>|iterable<int>  $recipientIds
     */
    private function fanOut(iterable $recipientIds, int $agentId, DomainNotification $notification): void
    {
        $ids = collect($recipientIds)->filter()->reject(fn ($id) => (int) $id === $agentId)->unique();

        foreach (User::query()->findMany($ids) as $recipient) {
            $recipient->notify($notification);
        }
    }
}
