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
            'wilaya_id', 'commune_id', 'type_id', 'floor_id', 'floor_pref',
            'area_min', 'area_max', 'rooms_min', 'budget_min', 'budget_max', 'notes',
        ]);

        $desire = Desire::updateOrCreate(
            ['client_id' => $client->id, 'client_project_id' => null],
            $attributes,
        );

        // Preferred sites: absent key = leave as-is; sent (even empty) = re-sync.
        if (array_key_exists('location_ids', $data)) {
            $desire->locations()->sync($data['location_ids'] ?? []);
        }

        return $desire;
    }
}
