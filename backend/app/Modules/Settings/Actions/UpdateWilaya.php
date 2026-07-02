<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\Wilaya;
use Illuminate\Support\Arr;

class UpdateWilaya
{
    public function handle(Wilaya $wilaya, array $data): Wilaya
    {
        $wilaya->update(Arr::only($data, ['code', 'name']));

        return $wilaya->fresh();
    }
}
