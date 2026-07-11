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

        $location = Location::create(Arr::only($data, [
            'name', 'code', 'wilaya_id', 'commune_id', 'type_id', 'contract_type_id', 'address',
            'description', 'expected_delivery_date', 'gtm_priority', 'latitude', 'longitude',
            // Public-website controls (the /plaza showcase).
            'is_published', 'show_prices', 'show_availability',
            'marketing_tagline', 'marketing_description', 'construction_progress',
        ]));

        // The financing / payment options the project offers buyers.
        if (array_key_exists('payment_method_ids', $data)) {
            $location->paymentMethods()->sync($data['payment_method_ids'] ?? []);
        }

        return $location;
    }
}
