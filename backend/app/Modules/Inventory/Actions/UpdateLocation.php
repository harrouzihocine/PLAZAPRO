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
            'name', 'code', 'wilaya_id', 'commune_id', 'type_id', 'contract_type_id', 'address',
            'description', 'expected_delivery_date', 'gtm_priority', 'latitude', 'longitude',
            'cover_media_id', 'cover_focus_x', 'cover_focus_y',
        ]));

        // Sync the offered payment options only when the field was submitted, so
        // a partial update (e.g. cover image) never clears them.
        if (array_key_exists('payment_method_ids', $data)) {
            $location->paymentMethods()->sync($data['payment_method_ids'] ?? []);
        }

        return $location->fresh();
    }
}
