<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Events\VisitAssigned;

/**
 * When a field agent is dispatched to a project's in-site visit, give them
 * read + write access to that project's chat so they can coordinate on the
 * visit — WITHOUT making them a project contributor (they are attached to the
 * conversation directly with ROLE_FIELD_AGENT, never to client_project_viewers,
 * so they stay out of the viewers panel and the upcoming-work digest).
 *
 * Listens to VisitAssigned — the one event fired by every path that sets a
 * visit's agent (AssignVisit, SyncVisitFromNextAction). Runs synchronously (NOT
 * queued): the grant must be visible in the same request that assigns.
 */
class GrantFieldAgentChatAccess
{
    public function __construct(private EnsureProjectConversation $ensureConversation) {}

    public function handle(VisitAssigned $event): void
    {
        $visit = $event->visit;

        // Only in-site (field) visits confer chat access, and only when they
        // belong to a project (a chat's subject). Office/qualifying visits and
        // project-less visits carry no project chat.
        if ($visit->type !== VisitType::InSite || $visit->client_project_id === null) {
            return;
        }

        $agentId = $visit->agent_id;
        if ($agentId === null) {
            return;
        }

        $project = $visit->clientProject;
        if ($project === null) {
            return;
        }

        // Ensure the thread exists and its real contributors are synced first.
        $conversation = $this->ensureConversation->handle($project);

        $existing = $conversation->participants()
            ->where('users.id', $agentId)
            ->first();

        if ($existing !== null) {
            // A genuine contributor (creator / viewer) who happens to be the
            // dispatched agent keeps their real role — never downgrade them.
            if (in_array($existing->pivot->role, ['admin', 'member'], true)) {
                return;
            }

            // Re-grant after a prior downgrade to observer: just flip the role
            // back (the row + its joined_at / read cursor are preserved).
            $conversation->participants()->updateExistingPivot($agentId, [
                'role' => Conversation::ROLE_FIELD_AGENT,
            ]);

            return;
        }

        // First grant: join the thread as a field agent (can read the history).
        $conversation->participants()->attach($agentId, [
            'role' => Conversation::ROLE_FIELD_AGENT,
            'joined_at' => now(),
        ]);
    }
}
