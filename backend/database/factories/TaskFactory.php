<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Pipeline\Enums\TaskPriority;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'assigned_to' => User::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'due_at' => now()->addDays(2),
            'priority' => TaskPriority::Normal->value,
            'state' => TaskState::Open->value,
        ];
    }
}
