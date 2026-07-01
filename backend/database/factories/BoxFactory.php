<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Box>
 */
class BoxFactory extends Factory
{
    protected $model = Box::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'reference' => 'P-'.fake()->unique()->numberBetween(1, 999999),
            'price' => fake()->numberBetween(5000, 40000),
            'sale_status' => SaleStatus::Available->value,
            'unit_id' => null,
        ];
    }
}
