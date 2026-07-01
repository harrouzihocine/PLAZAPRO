<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Collaboration\Enums\AttachmentKind;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Collaboration\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    protected $model = MessageAttachment::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'kind' => AttachmentKind::Image->value,
            'disk' => 'chat',
            'path' => 'attachments/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1000, 500000),
            'duration_ms' => null,
            'width' => 800,
            'height' => 600,
            'meta' => null,
        ];
    }

    public function voice(): static
    {
        return $this->state(fn () => [
            'kind' => AttachmentKind::Voice->value,
            'path' => 'attachments/'.fake()->uuid().'.webm',
            'mime_type' => 'audio/webm',
            'duration_ms' => fake()->numberBetween(1000, 60000),
            'width' => null,
            'height' => null,
        ]);
    }
}
