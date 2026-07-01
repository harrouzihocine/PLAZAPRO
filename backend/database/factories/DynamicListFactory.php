<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Settings\Models\DynamicList;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DynamicList>
 */
class DynamicListFactory extends Factory
{
    protected $model = DynamicList::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'key' => Str::slug($name, '_').'_'.fake()->unique()->numberBetween(1, 99999),
            'name' => Str::headline($name),
            'description' => null,
            'is_system' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => ['is_system' => true]);
    }
}
