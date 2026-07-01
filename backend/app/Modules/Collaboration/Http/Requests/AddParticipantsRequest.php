<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Requests;

use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;

class AddParticipantsRequest extends FormRequest
{
    /**
     * Only a group admin may add members. The admin check is a plain boolean, so
     * the super-admin Gate::before shortcut cannot widen it.
     */
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation
            && $conversation->type === ConversationType::Group
            && (bool) $this->user()?->can('chat.use')
            && $conversation->isAdmin($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }
}
