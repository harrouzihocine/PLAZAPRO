<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Client;

/**
 * Create a client. Input is already validated (StoreClientRequest), including the
 * agent-only check on assigned_agent_id; $fillable is the final allow-list.
 */
class CreateClient
{
    public function handle(array $data): Client
    {
        return Client::create($data);
    }
}
