<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\Desire;
use Illuminate\Support\Arr;

/**
 * Create or update a client's (client-level) desire — the criteria matched against
 * inventory. One desire per client, so this upserts on client_id.
 */
class UpsertDesire
{
    public function handle(Client $client, array $data): Desire
    {
        $attributes = Arr::only($data, [
            'wilaya_id', 'commune_id', 'type_id', 'floor_pref', 'budget_min', 'budget_max', 'notes',
        ]);

        return Desire::updateOrCreate(
            ['client_id' => $client->id, 'client_project_id' => null],
            $attributes,
        );
    }
}
