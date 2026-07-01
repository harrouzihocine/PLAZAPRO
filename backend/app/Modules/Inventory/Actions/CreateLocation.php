<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Location;
use Illuminate\Support\Arr;

class CreateLocation
{
    public function handle(array $data): Location
    {
        return Location::create(Arr::only($data, [
            'name', 'code', 'area_id', 'address', 'description', 'latitude', 'longitude',
        ]));
    }
}
