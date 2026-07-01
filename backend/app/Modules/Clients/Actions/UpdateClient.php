<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Client;

/**
 * Update a client, including (re)assigning the agent. The ordinary edit is logged
 * automatically (LogsActivity). Validation, incl. the agent-only rule, happens in
 * UpdateClientRequest.
 */
class UpdateClient
{
    public function handle(Client $client, array $data): Client
    {
        $client->update($data);

        return $client->fresh();
    }
}
