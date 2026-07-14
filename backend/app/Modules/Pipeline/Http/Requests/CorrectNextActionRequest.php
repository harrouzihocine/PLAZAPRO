<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\NextAction;
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
        $user = $this->user();

        if ($user === null || ! $user->can('logs.edit_next_action')) {
            return false;
        }

        // Correcting a plan ON a project the user is only dispatched to (a field
        // agent, not a contributor / the client's own agent) is outside their
        // remit — same rule as logging its calls or planning on it standalone.
        // A visit administrator is exempt.
        $nextAction = $this->route('nextAction');
        if ($nextAction instanceof NextAction
            && $nextAction->subject_type === 'client_project'
            && ! $user->can('visits.assign')) {
            $project = ClientProject::find((int) $nextAction->subject_id);
            if ($project?->isDispatchOnlyAgent($user)) {
                return false;
            }
        }

        return true;
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
