<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Resources;

use App\Modules\Collaboration\Models\Message;
use App\Modules\Collaboration\Support\SharedSubject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chat message. A redacted (cancelled) message keeps its slot in the thread
 * but its body and attachments are withheld. The shared-record `subject` card is
 * added in the visibility & sharing slice (RBAC-gated).
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $redacted = $this->isCancelled();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'type' => $this->type->value,
            'body' => $redacted ? null : $this->body,
            'redacted' => $redacted,
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null),
            'attachments' => $redacted
                ? []
                : MessageAttachmentResource::collection($this->whenLoaded('attachments')),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            // The shared-record card — revealed only to participants whose RBAC
            // permits viewing that record; others see it as restricted.
            'subject' => $this->when(
                $this->subject_type !== null,
                fn () => $this->subjectCard($request),
            ),
            'is_mine' => $request->user()?->id === $this->user_id,
            'created_at' => $this->created_at,
            'edited_at' => $this->edited_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subjectCard(Request $request): array
    {
        if (! $this->relationLoaded('subject') || $this->subject === null) {
            return ['restricted' => true, 'type' => $this->subject_type];
        }

        $card = SharedSubject::card($this->subject);

        // Unknown type, or the viewer lacks the record's view permission: reveal
        // only that *something* was shared, never its contents.
        if ($card === null || ! $request->user()?->can($card['permission'])) {
            return ['restricted' => true, 'type' => $this->subject_type];
        }

        return [
            'restricted' => false,
            'type' => $card['type'],
            'id' => $card['id'],
            'label' => $card['label'],
            'link' => $card['link'],
        ];
    }
}
