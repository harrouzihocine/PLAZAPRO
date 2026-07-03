<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Support\Collection;

/**
 * When an office visit's next step is an in-site visit, the system auto-generates a
 * pending in-site (field) visit for each shortlisted UNIT (apartments / locals; boxes
 * are ancillary), assigned to the chosen field agent. The site agent then fills each
 * one — or adds/removes visits — from there. Idempotent: skips a unit that already
 * has an open in-site visit, so re-completing the office visit won't duplicate them.
 */
class GenerateInSiteVisits
{
    public function handle(ClientProject $project, int $agentId, mixed $scheduledAt, ?int $nextActionId = null): Collection
    {
        $created = collect();

        $units = $project->shortlistItems()->active()
            ->where('shortlistable_type', 'unit')
            ->whereIn('state', ['shortlisted', 'not_visited'])
            ->get();

        foreach ($units as $item) {
            $alreadyOpen = Visit::query()->active()
                ->where('client_project_id', $project->id)
                ->where('unit_id', $item->shortlistable_id)
                ->where('type', VisitType::InSite->value)
                ->whereNull('completed_at')
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            $created->push(Visit::create([
                'client_id' => $project->client_id,
                'client_project_id' => $project->id,
                'type' => VisitType::InSite->value,
                'unit_id' => $item->shortlistable_id,
                'agent_id' => $agentId,
                'next_action_id' => $nextActionId,
                'scheduled_at' => $scheduledAt,
            ]));
        }

        return $created;
    }
}
