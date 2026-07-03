<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\CallDirection;
use App\Modules\Pipeline\Http\Requests\Concerns\ValidatesNextAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            // same call (mirrors UpsertDesireRequest).
            'desire' => ['nullable', 'array'],
            'desire.wilaya_id' => ['nullable', 'integer', 'exists:wilayas,id'],
            'desire.commune_id' => ['nullable', 'integer', 'exists:communes,id'],
            'desire.type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'desire.floor_pref' => ['nullable', 'string', 'max:255'],
            'desire.budget_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'desire.budget_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'desire.notes' => ['nullable', 'string', 'max:5000'],
        ], $this->nextActionRules());
    }
}
