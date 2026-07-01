<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Pipeline\Enums\ReminderChannel;
use App\Modules\Pipeline\Enums\ReminderState;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Reminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    public function definition(): array
    {
        return [
            'next_action_id' => NextAction::factory(),
            'task_id' => null,
            'remind_at' => now(),
            'channel' => ReminderChannel::InApp->value,
            'sent_at' => null,
            'state' => ReminderState::Pending->value,
        ];
    }

    public function due(): static
    {
        return $this->state(fn () => ['remind_at' => now()->subMinute()]);
    }
}
