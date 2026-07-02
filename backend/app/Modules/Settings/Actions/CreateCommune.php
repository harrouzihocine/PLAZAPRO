<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Support\Arr;

class CreateCommune
{
    public function handle(Wilaya $wilaya, array $data): Commune
    {
        return $wilaya->communes()->create(Arr::only($data, ['name', 'daira_name']));
    }
}
