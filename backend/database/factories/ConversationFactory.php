<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'type' => ConversationType::Direct->value,
            'title' => null,
            'subject_type' => null,
            'subject_id' => null,
            'created_by' => User::factory(),
            'last_message_at' => null,
        ];
    }

    public function group(?string $title = null): static
    {
        return $this->state(fn () => [
            'type' => ConversationType::Group->value,
            'title' => $title ?? fake()->words(2, true),
        ]);
    }
}
