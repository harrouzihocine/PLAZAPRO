<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Client;

/**
 * Cancel (no-delete) a client. The row and its history are kept and marked
 * cancelled (audited via LogsActivity).
 */
class CancelClient
{
    public function handle(Client $client, string $reason): Client
    {
        return $client->cancel($reason);
    }
}
