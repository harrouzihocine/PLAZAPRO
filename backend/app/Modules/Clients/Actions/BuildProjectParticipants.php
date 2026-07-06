<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Settings\Models\User;

/**
 * The people who worked a project — everyone who appears in its timeline (its
 * creator, the agents who logged calls, ran visits, and opened deals). Feeds the
 * "who deserves credit" pickers on a sale: the same pool backs both the sale-agent
 * and in-site-agent selectors, with sensible defaults pre-computed (in-site visit
 * agents; the marketing side = creator + call/office agents).
 */
class BuildProjectParticipants
{
    /**
     * @return array{participants: list<array{id:int, name:string}>, insite_agent_ids: list<int>, sale_agent_ids: list<int>}
     */
    public function handle(ClientProject $project): array
    {
        $callAgentIds = $project->calls()->whereNotNull('agent_id')->distinct()->pluck('agent_id');
        $insiteAgentIds = $project->visits()
            ->where('type', VisitType::InSite->value)
            ->whereNotNull('agent_id')->distinct()->pluck('agent_id');
        $officeAgentIds = $project->visits()
            ->where('type', VisitType::Office->value)
            ->whereNotNull('agent_id')->distinct()->pluck('agent_id');
        $dealCreatorIds = $project->deals()->whereNotNull('created_by')->distinct()->pluck('created_by');
        $creatorId = $project->created_by !== null ? collect([$project->created_by]) : collect();

        $allIds = $creatorId
            ->merge($callAgentIds)->merge($insiteAgentIds)
            ->merge($officeAgentIds)->merge($dealCreatorIds)
            ->map(fn ($id) => (int) $id)->unique()->values();

        $users = User::query()->whereIn('id', $allIds)->orderBy('name')->get(['id', 'name']);

        $toIntList = fn ($c) => $c->map(fn ($id) => (int) $id)->unique()->values()->all();

        return [
            'participants' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'insite_agent_ids' => $toIntList($insiteAgentIds),
            'sale_agent_ids' => $toIntList($creatorId->merge($callAgentIds)->merge($officeAgentIds)),
        ];
    }
}
