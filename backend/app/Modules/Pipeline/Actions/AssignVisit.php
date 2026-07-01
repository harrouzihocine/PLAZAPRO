<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Events\VisitAssigned;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;

/**
 * (Re)assign a visit to an agent. Agent-only assignment is enforced here (the
 * is_agent query) as well as in the FormRequest — a visit can never be assigned
 * to a non-agent.
 */
class AssignVisit
{
    public function handle(Visit $visit, int|string $agentId): Visit
    {
        $agent = User::query()->with('role')->find($agentId);

        abort_unless(
            $agent && $agent->is_active && $agent->isActive() && $agent->isAgent(),
            422,
            'Visits can only be assigned to an active agent.',
        );

        $visit->update(['agent_id' => $agent->id]);

        $fresh = $visit->fresh();

        // Notify the newly assigned agent (Collaboration listens; Phase 5).
        VisitAssigned::dispatch($fresh);

        return $fresh;
    }
}
