<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commune>
 */
class CommuneFactory extends Factory
{
    protected $model = Commune::class;

    public function definition(): array
    {
        return [
            'wilaya_id' => Wilaya::factory(),
            'name' => fake()->unique()->city(),
            'daira_name' => fake()->optional()->city(),
        ];
    }
}
