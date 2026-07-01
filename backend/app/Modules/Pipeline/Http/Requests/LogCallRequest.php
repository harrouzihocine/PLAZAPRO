<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\CallDirection;
use App\Modules\Pipeline\Http\Requests\Concerns\ValidatesNextAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class LogCallRequest extends FormRequest
{
    use ValidatesNextAction;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('calls.log');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'client_project_id' => ['nullable', 'integer', 'exists:client_projects,id'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'direction' => ['required', new Enum(CallDirection::class)],
            'outcome_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'called_at' => ['nullable', 'date'],
        ], $this->nextActionRules());
    }
}
