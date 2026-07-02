<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\Desire;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Desire>
 */
class DesireFactory extends Factory
{
    protected $model = Desire::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'client_project_id' => null,
            'wilaya_id' => null,
            'commune_id' => null,
            'type_id' => null,
            'floor_pref' => null,
            'budget_min' => null,
            'budget_max' => null,
            'notes' => null,
        ];
    }
}
