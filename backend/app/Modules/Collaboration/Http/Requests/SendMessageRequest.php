<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Requests;

use App\Modules\Collaboration\Enums\AttachmentKind;
use App\Modules\Collaboration\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    /**
     * Authorised only for participants of this conversation. The participant
     * check is a plain boolean (not a Gate call), so the super-admin Gate::before
     * shortcut cannot widen it — a non-participant admin is still refused.
     */
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation
            && (bool) $this->user()?->can('chat.use')
            && $conversation->hasParticipant($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:5000', 'required_without:attachment'],
            'attachment' => [
                'nullable', 'file', 'required_without:body',
                'max:'.(25 * 1024), // hard ceiling; per-kind caps enforced in StoreAttachment
                'mimetypes:'.implode(',', AttachmentKind::allowedMimes()),
            ],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
