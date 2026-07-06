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
            'wilaya_id', 'commune_id', 'type_id', 'room_number_id', 'contract_type_id',
            'floor_id', 'floor_pref', 'area_min', 'area_max', 'rooms_min',
            'budget_min', 'budget_max', 'notes',
        ]);

        // updateOrCreate() matches on the key regardless of status, so a desire
        // EnsureActiveClientProject closed out (reconnected → active project) is
        // found again here, not duplicated. Revive it — otherwise it stays
        // "cancelled" forever, silently invisible to Desire Matches / the
        // matches tab even though it was just re-captured.
        $desire = Desire::updateOrCreate(
            ['client_id' => $client->id, 'client_project_id' => null],
            $attributes,
        );

        if ($desire->isCancelled()) {
            $desire->restore();
        }

        // Preferred sites: absent key = leave as-is; sent (even empty) = re-sync.
        if (array_key_exists('location_ids', $data)) {
            $desire->locations()->sync($data['location_ids'] ?? []);
        }

        return $desire;
    }
}
