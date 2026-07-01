<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Requests;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Support\SharedSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareRecordRequest extends FormRequest
{
    /**
     * A participant may share, but only a record they themselves can view — you
     * cannot leak a record into a chat that you have no access to. Both checks are
     * plain booleans (not Gate on the conversation), so the super-admin shortcut
     * cannot widen participation.
     */
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');
        $permission = SharedSubject::permissionFor((string) $this->input('subject_type'));

        return $conversation instanceof Conversation
            && (bool) $this->user()?->can('chat.use')
            && $conversation->hasParticipant($this->user())
            && $permission !== null
            && (bool) $this->user()?->can($permission);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(SharedSubject::aliases())],
            'subject_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
