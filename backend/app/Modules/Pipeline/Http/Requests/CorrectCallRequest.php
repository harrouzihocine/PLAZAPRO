<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\CallDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Correct a logged call. A reason is mandatory — the edit is kept in history as a
 * cancel + new version (CorrectCall → supersedeWith).
 */
class CorrectCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('calls.log');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'direction' => ['required', new Enum(CallDirection::class)],
            'outcome_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'topics' => ['nullable', 'array'],
            'topics.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            'objections' => ['nullable', 'array'],
            'objections.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            'called_at' => ['nullable', 'date'],
        ];
    }
}
