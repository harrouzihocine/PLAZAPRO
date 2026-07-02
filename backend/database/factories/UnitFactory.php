<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'reference' => 'U-'.fake()->unique()->numberBetween(1, 999999),
            'area_sqm' => fake()->numberBetween(30, 200),
            'price' => fake()->numberBetween(50000, 900000),
            'sale_status' => SaleStatus::Available->value,
            'block' => fake()->randomElement(['A', 'B', 'C']),
            'stack_floor' => fake()->numberBetween(0, 10),
            'position' => fake()->numberBetween(1, 6),
            'gtm_priority' => fake()->randomElement(GtmPriority::cases())->value,
        ];
    }

    public function reserved(): static
    {
        return $this->state(fn () => ['sale_status' => SaleStatus::Reserved->value]);
    }

    public function sold(): static
    {
        return $this->state(fn () => ['sale_status' => SaleStatus::Sold->value]);
    }
}
