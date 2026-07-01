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
            'name', 'code', 'area_id', 'address', 'description', 'latitude', 'longitude',
        ]));

        return $location->fresh();
    }
}
