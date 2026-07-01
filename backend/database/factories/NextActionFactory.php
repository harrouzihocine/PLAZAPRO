<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\Client;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NextAction>
 */
class NextActionFactory extends Factory
{
    protected $model = NextAction::class;

    public function definition(): array
    {
        return [
            'subject_type' => 'client',
            'subject_id' => Client::factory(),
            'source_type' => null,
            'source_id' => null,
            'type' => NextActionType::FollowUp->value,
            'due_at' => now()->addDay(),
            'assigned_to' => User::factory()->agent(),
            'state' => NextActionState::Pending->value,
            'completed_at' => null,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_at' => now()->subDay()]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'state' => NextActionState::Done->value,
            'completed_at' => now(),
        ]);
    }
}
