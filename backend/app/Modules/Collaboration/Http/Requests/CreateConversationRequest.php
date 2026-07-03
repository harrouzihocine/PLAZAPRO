<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Requests;

use App\Modules\Collaboration\Enums\ConversationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('chat.use');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Project threads are never created by hand — they belong to their
            // project (EnsureProjectConversation) and sync with its contributors.
            'type' => ['required', new Enum(ConversationType::class), Rule::notIn([ConversationType::Project->value])],
            'title' => ['nullable', 'string', 'max:255', Rule::requiredIf($this->input('type') === 'group')],
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            // Optional contextual link (client/unit/deal) for the whole thread.
            'subject_type' => ['nullable', 'string', 'max:255', 'required_with:subject_id'],
            'subject_id' => ['nullable', 'integer', 'required_with:subject_type'],
        ];
    }
}
