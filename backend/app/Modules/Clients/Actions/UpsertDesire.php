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
    /** Multi-valued criteria: payload key → pivot relation, all synced alike. */
    private const CRITERIA = [
        'wilaya_ids' => 'wilayas',
        'commune_ids' => 'communes',
        'type_ids' => 'types',
        'room_number_ids' => 'roomNumbers',
        'contract_type_ids' => 'contractTypes',
        'floor_ids' => 'floors',
        'location_ids' => 'locations',
    ];

    public function handle(Client $client, array $data): Desire
    {
        $attributes = Arr::only($data, [
            'floor_pref', 'area_min', 'area_max', 'rooms_min',
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

        // Each multi criterion: absent key = leave as-is; sent (even empty) =
        // re-sync. No pivot rows means "no preference".
        foreach (self::CRITERIA as $key => $relation) {
            if (array_key_exists($key, $data)) {
                $desire->{$relation}()->sync($data[$key] ?? []);
            }
        }

        return $desire;
    }
}
