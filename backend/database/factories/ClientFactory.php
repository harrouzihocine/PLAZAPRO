<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->numerify('05########'),
            'email' => fake()->optional()->safeEmail(),
            'source_id' => null,
            'rating_id' => null,
            'assigned_agent_id' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
