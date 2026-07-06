<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\BoxPublished;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use Illuminate\Support\Arr;

class CreateBox
{
    public function handle(Location $location, array $data): Box
    {
        $attributes = Arr::only($data, ['reference', 'type_id', 'price', 'sale_status', 'unit_id']);
        $attributes['sale_status'] ??= SaleStatus::Available->value;

        $box = $location->boxes()->create($attributes);

        // Alert the whole team a new box was added (Collaboration drops a durable
        // bell + fires the live toast).
        BoxPublished::dispatch($box);

        return $box;
    }
}
