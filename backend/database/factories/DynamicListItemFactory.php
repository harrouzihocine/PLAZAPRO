<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DynamicListItem>
 */
class DynamicListItemFactory extends Factory
{
    protected $model = DynamicListItem::class;

    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'dynamic_list_id' => DynamicList::factory(),
            'label' => Str::headline($label),
            'value' => Str::slug($label, '_'),
            'sort_order' => 0,
            'parent_id' => null,
            'is_active' => true,
            'meta' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
