<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Settings\Rules\IsAgentUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Correct the enforced next action (e.g. change a follow-up "call" into an
 * "in-site visit") with a reason picked from the next_action_change_reasons
 * dynamic list, optionally with a free note. Same who/when rules as creating one:
 * an in-site visit is either handed to a field agent or left for the dispatch
 * board; every other type may leave the assignee blank (defaults to the
 * current one).
 */
class CorrectNextActionRequest extends FormRequest
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
            'reason_id' => ['required', 'integer', 'exists:dynamic_list_items,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', new Enum(NextActionType::class)],
            'due_date' => ['required', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'assigned_to' => $this->input('type') === NextActionType::InSiteVisit->value
                ? ['nullable', 'integer', new IsAgentUser]
                : ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
