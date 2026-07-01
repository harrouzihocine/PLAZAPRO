<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Collaboration\Enums\MessageType;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'type' => MessageType::Text->value,
            'body' => fake()->sentence(),
            'subject_type' => null,
            'subject_id' => null,
        ];
    }
}
