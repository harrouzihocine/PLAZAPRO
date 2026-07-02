<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Settings\Models\Wilaya;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wilaya>
 */
class WilayaFactory extends Factory
{
    protected $model = Wilaya::class;

    public function definition(): array
    {
        return [
            'code' => str_pad((string) fake()->unique()->numberBetween(1, 99), 2, '0', STR_PAD_LEFT),
            'name' => fake()->unique()->city(),
        ];
    }
}
