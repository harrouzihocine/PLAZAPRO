<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\Wilaya;
use Illuminate\Support\Arr;

class CreateWilaya
{
    public function handle(array $data): Wilaya
    {
        return Wilaya::create(Arr::only($data, ['code', 'name']));
    }
}
