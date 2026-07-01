<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'name' => 'Résidence '.fake()->unique()->lastName(),
            'code' => strtoupper(Str::random(3)).'-'.fake()->unique()->numberBetween(100, 99999),
            'area_id' => null,
            'address' => fake()->streetAddress(),
            'description' => fake()->optional()->sentence(),
            'latitude' => fake()->optional()->latitude(),
            'longitude' => fake()->optional()->longitude(),
        ];
    }
}
