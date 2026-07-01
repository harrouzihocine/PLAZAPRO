<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use Illuminate\Support\Arr;

/**
 * Open a new deal for a client. A deal starts at the `lead` stage unless a valid
 * starting stage is given.
 */
class CreateClientProject
{
    public function handle(Client $client, array $data): ClientProject
    {
        $attributes = Arr::only($data, ['location_id', 'unit_id', 'stage', 'total_price']);
        $attributes['stage'] ??= ClientProjectStage::Lead->value;

        return $client->projects()->create($attributes);
    }
}
