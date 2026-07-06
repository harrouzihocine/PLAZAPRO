<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Models\User;

/**
 * Hand a waiting client (a desire on the matches board) to the sales agent who
 * will do the reconnect — the manager delegates, the agent does the calling.
 *
 * Assignment is just the client's `assigned_agent_id`: it is what scopes the
 * Desire Matches board (an agent sees only their own book), so setting it moves
 * the lead onto that agent's board and off everyone else's. The agent is
 * notified in real time so they know to reconnect; a no-op re-assign (same
 * agent) neither re-notifies nor re-logs.
 *
 * Validation (the assignee is an active sales agent who can follow a client up)
 * lives in AssignClientAgentRequest — the same CanFollowUpClient rule the client
 * form uses, so "who may own a client" has a single source of truth.
 */
class AssignClientAgent
{
    public function handle(Client $client, int $agentId): Client
    {
        if ((int) $client->assigned_agent_id === $agentId) {
            return $client->fresh(['assignedAgent']);
        }

        $client->update(['assigned_agent_id' => $agentId]);

        $agent = User::find($agentId);
        $agent?->notify(new DomainNotification(
            kind: 'desire_assigned',
            title: 'A waiting client was assigned to you',
            body: "Reconnect with {$client->full_name} — their wishlist now fits available inventory.",
            link: '/desires/matches',
            subjectType: 'client',
            subjectId: $client->id,
        ));

        return $client->fresh(['assignedAgent']);
    }
}
