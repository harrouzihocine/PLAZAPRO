<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\Commune;
use Illuminate\Support\Arr;

class UpdateCommune
{
    public function handle(Commune $commune, array $data): Commune
    {
        $commune->update(Arr::only($data, ['name', 'daira_name']));

        return $commune->fresh();
    }
}
