<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Settings\Rules\IsAgentUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Plan a next action AFTER the fact — for a log that didn't need one at the
 * time (next actions are optional on call/visit completion). Subject is the
 * client, or one of their projects when client_project_id is sent. Mirrors the
 * ValidatesNextAction rules, flat (no next_action wrapper).
 */
class StoreNextActionRequest extends FormRequest
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
            'client_project_id' => [
                'nullable', 'integer',
                Rule::exists('client_projects', 'id')->where('client_id', $this->route('client')?->id),
            ],
            'type' => ['required', new Enum(NextActionType::class)],
            'due_date' => ['required', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'assigned_to' => $this->input('type') === NextActionType::InSiteVisit->value
                ? ['required', 'integer', new IsAgentUser]
                : ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
