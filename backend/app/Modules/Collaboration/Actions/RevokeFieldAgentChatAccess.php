<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Visit;

/**
 * The counterpart to GrantFieldAgentChatAccess: when a dispatcher un-assigns a
 * field agent from a project's in-site visit (returns it to the pool or hands
 * it to someone else), downgrade their project-chat row to read-only
 * (ROLE_FIELD_AGENT_OBSERVER) — kept for history, but the composer locks.
 *
 * Called directly from the dispatch flow (AssignDispatchItem); un-assignment
 * fires no event, so there is no listener choke point to hang this on.
 */
class RevokeFieldAgentChatAccess
{
    public function handle(ClientProject $project, int $agentId): void
    {
        // Keep access while the agent still has ANY open in-site visit on this
        // project — only the loss of his last one downgrades him.
        $stillAssigned = Visit::query()->active()
            ->where('client_project_id', $project->id)
            ->where('agent_id', $agentId)
            ->where('type', VisitType::InSite->value)
            ->whereNull('completed_at')
            ->exists();

        if ($stillAssigned) {
            return;
        }

        $conversation = Conversation::query()
            ->where('type', ConversationType::Project->value)
            ->where('subject_type', $project->getMorphClass())
            ->where('subject_id', $project->id)
            ->first();

        if ($conversation === null) {
            return;
        }

        // Only downgrade a live field-agent grant. A real contributor (admin /
        // member) is untouched; an already-downgraded observer is left as-is.
        $affected = $conversation->participants()
            ->where('users.id', $agentId)
            ->wherePivot('role', Conversation::ROLE_FIELD_AGENT)
            ->exists();

        if (! $affected) {
            return;
        }

        $conversation->participants()->updateExistingPivot($agentId, [
            'role' => Conversation::ROLE_FIELD_AGENT_OBSERVER,
        ]);
    }
}
