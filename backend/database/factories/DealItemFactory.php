<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealItem>
 */
class DealItemFactory extends Factory
{
    protected $model = DealItem::class;

    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'unit_id' => Unit::factory(),
            'box_id' => null,
        ];
    }
}
