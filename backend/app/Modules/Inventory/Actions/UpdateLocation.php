<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Location;
use Illuminate\Support\Arr;

class UpdateLocation
{
    public function handle(Location $location, array $data): Location
    {
        $location->update(Arr::only($data, [
            'name', 'code', 'wilaya_id', 'commune_id', 'contract_type_id', 'address',
            'description', 'expected_delivery_date', 'gtm_priority', 'latitude', 'longitude',
        ]));

        return $location->fresh();
    }
}
