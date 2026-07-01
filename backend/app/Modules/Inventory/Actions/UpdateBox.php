<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Box;
use Illuminate\Support\Arr;

class UpdateBox
{
    public function handle(Box $box, array $data): Box
    {
        $box->update(Arr::only($data, ['reference', 'type_id', 'price', 'sale_status', 'unit_id']));

        return $box->fresh();
    }
}
