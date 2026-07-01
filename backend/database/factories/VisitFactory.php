<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'client_project_id' => null,
            'type' => VisitType::Office->value,
            'unit_id' => null,
            'agent_id' => User::factory()->agent(),
            'scheduled_at' => now()->addDay(),
            'completed_at' => null,
            'outcome_id' => null,
            'notes' => null,
        ];
    }

    public function apartment(): static
    {
        return $this->state(fn () => [
            'type' => VisitType::Apartment->value,
            'unit_id' => Unit::factory(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['completed_at' => now()]);
    }
}
