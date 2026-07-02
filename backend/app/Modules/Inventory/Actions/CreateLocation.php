<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Models\Location;
use Illuminate\Support\Arr;

class CreateLocation
{
    public function handle(array $data): Location
    {
        // Default GTM priority to Medium so the value is present immediately
        // (mirrors the column default; a fresh instance isn't reloaded from DB).
        $data['gtm_priority'] ??= GtmPriority::Medium->value;

        return Location::create(Arr::only($data, [
            'name', 'code', 'wilaya_id', 'commune_id', 'address', 'description',
            'expected_delivery_date', 'gtm_priority', 'latitude', 'longitude',
        ]));
    }
}
