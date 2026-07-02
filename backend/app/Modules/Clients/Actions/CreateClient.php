<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Client;
use Illuminate\Support\Facades\Auth;

/**
 * Create a client. Input is already validated (StoreClientRequest), including the
 * agent-only check on assigned_agent_id; $fillable is the final allow-list.
 * created_by is stamped from the authenticated user (not client-supplied).
 */
class CreateClient
{
    public function handle(array $data): Client
    {
        $client = new Client($data);
        $client->created_by = Auth::id();
        $client->save();

        return $client;
    }
}
