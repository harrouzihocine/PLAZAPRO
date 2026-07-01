<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Settings\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'description' => null,
            'is_agent' => false,
        ];
    }

    public function agent(): static
    {
        return $this->state(fn (array $attributes) => ['is_agent' => true]);
    }
}
