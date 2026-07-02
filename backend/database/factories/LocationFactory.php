<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Enums\GtmPriority;
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
            'wilaya_id' => null,
            'commune_id' => null,
            'address' => fake()->streetAddress(),
            'description' => fake()->optional()->sentence(),
            'expected_delivery_date' => fake()->optional()->dateTimeBetween('now', '+2 years')?->format('Y-m-d'),
            'gtm_priority' => fake()->randomElement(GtmPriority::cases())->value,
            'latitude' => fake()->optional()->latitude(),
            'longitude' => fake()->optional()->longitude(),
        ];
    }
}
