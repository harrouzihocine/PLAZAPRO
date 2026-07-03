<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Clients\Http\Requests\Concerns\ValidatesDesireFields;
use App\Modules\Pipeline\Enums\CallDirection;
use App\Modules\Pipeline\Http\Requests\Concerns\ValidatesNextAction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class LogCallRequest extends FormRequest
{
    use ValidatesDesireFields;
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
            // A linked deal must belong to this client (route-bound).
            'client_project_id' => [
                'nullable', 'integer',
                Rule::exists('client_projects', 'id')->where('client_id', $this->route('client')?->id),
            ],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'direction' => ['required', new Enum(CallDirection::class)],
            'outcome_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Fast checkbox talking-points (call_topics item ids).
            'topics' => ['nullable', 'array'],
            'topics.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            'called_at' => ['nullable', 'date'],
            // Branch B — matching inventory: specific properties the client wants,
            // added to the deal's shortlist (existence re-checked in AddShortlistItems).
            'properties' => ['nullable', 'array'],
            'properties.*.shortlistable_type' => ['required', 'in:unit,box'],
            'properties.*.shortlistable_id' => ['required', 'integer'],
            'properties.*.note' => ['nullable', 'string', 'max:1000'],
            // Branch A — no matching inventory: capture the desire profile in the
            // same call (shared field set — notes required when the branch is used).
            'desire' => ['nullable', 'array'],
        ], $this->desireFieldRules('desire'), $this->nextActionRules());
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateDesireRanges($validator, 'desire');
    }
}
