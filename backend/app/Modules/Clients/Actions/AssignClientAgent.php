<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Models\User;

/**
 * Hand a waiting client (a desire on the company-wide matches board) to the sales
 * agent who will do the reconnect — the manager triages on the oversight board and
 * delegates, the agent does the calling.
 *
 * Assignment is just the client's `assigned_agent_id`: it records who owns the
 * reconnect and tags the owner on the (oversight-only) Desire Matches board. The
 * agent is notified in real time and the notification links straight to the client
 * file — where they reconnect (log the call) — since the board itself is an
 * oversight monitor they do not see. A no-op re-assign (same agent) neither
 * re-notifies nor re-logs.
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
            key: 'client_assigned',
            params: ['name' => $client->full_name],
            link: '/clients/'.$client->id,
            subjectType: 'client',
            subjectId: $client->id,
        ));

        return $client->fresh(['assignedAgent']);
    }
}
