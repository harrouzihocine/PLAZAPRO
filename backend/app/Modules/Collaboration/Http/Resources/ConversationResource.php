<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Resources;

use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation for the inbox. The display title is the group name, or — for a
 * direct thread — the other participant's name. Carries the current user's unread
 * count (set via withCount on the index query) and a last-message preview.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $me = $request->user();

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->displayTitle($me),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'last_message_at' => $this->last_message_at,
            'unread_count' => (int) ($this->getAttribute('unread_count') ?? 0),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->pivot->role,
                'muted' => (bool) $u->pivot->muted,
            ])->values()),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage
                ? [
                    'id' => $this->latestMessage->id,
                    'type' => $this->latestMessage->type->value,
                    'preview' => $this->preview($this->latestMessage),
                    'created_at' => $this->latestMessage->created_at,
                ]
                : null),
        ];
    }

    private function displayTitle(?User $me): ?string
    {
        if ($this->type === ConversationType::Group) {
            return $this->title;
        }

        // Direct: show the other participant's name (fall back to any single one).
        if ($this->relationLoaded('participants')) {
            $other = $this->participants->firstWhere('id', '!=', $me?->id) ?? $this->participants->first();

            return $other?->name;
        }

        return $this->title;
    }

    private function preview(Message $message): string
    {
        if ($message->isCancelled()) {
            return 'Message deleted';
        }

        return match ($message->type->value) {
            'image' => '📷 Photo',
            'voice' => '🎤 Voice note',
            'file' => '📎 File',
            default => (string) ($message->body ?? ''),
        };
    }
}
