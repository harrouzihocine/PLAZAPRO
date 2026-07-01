<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\Client;
use App\Modules\Pipeline\Enums\CallDirection;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'client_project_id' => null,
            'agent_id' => User::factory(),
            'direction' => CallDirection::Outbound->value,
            'outcome_id' => null,
            'notes' => fake()->optional()->sentence(),
            'called_at' => now(),
        ];
    }
}
