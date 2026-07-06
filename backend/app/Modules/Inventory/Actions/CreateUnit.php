<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\UnitPublished;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Arr;

class CreateUnit
{
    public function handle(Location $location, array $data): Unit
    {
        $attributes = Arr::only($data, [
            'reference', 'room_number_id', 'floor_id', 'area_sqm',
            'price', 'sale_status', 'block', 'stack_floor', 'position', 'gtm_priority',
        ]);

        // A new unit starts available at medium GTM priority unless explicitly
        // stated (also populates the in-memory attributes so the response reflects
        // the DB defaults).
        $attributes['sale_status'] ??= SaleStatus::Available->value;
        $attributes['gtm_priority'] ??= GtmPriority::Medium->value;

        $unit = $location->units()->create($attributes);

        // Alert agents whose clients' desires this unit matches (Collaboration
        // listens and runs the reverse desire-match; Phase 5).
        UnitPublished::dispatch($unit);

        return $unit;
    }
}
