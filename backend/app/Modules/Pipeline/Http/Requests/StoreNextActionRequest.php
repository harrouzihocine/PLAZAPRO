<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Settings\Rules\IsAgentUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Plan a next action AFTER the fact — for a log that didn't need one at the
 * time (next actions are optional on call/visit completion). Subject is the
 * client, or one of their projects when client_project_id is sent. Mirrors the
 * ValidatesNextAction rules, flat (no next_action wrapper). Needs
 * next_actions.plan — its own grant (split from calls.log) so the "Plan next
 * action" button can be handed out person-by-person.
 */
class StoreNextActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('next_actions.plan')) {
            return false;
        }

        // A plan AGAINST a project the user is only dispatched to (a field
        // agent, not a contributor / the client's own agent) is outside their
        // remit — they are here for the in-site visit; its follow-up is planned
        // inside that visit's completion. Client-level plans (no project) are
        // unaffected; a visit administrator is exempt. Same rule as LogCallRequest.
        $projectId = $this->input('client_project_id');
        if ($projectId !== null && ! $user->can('visits.assign')) {
            $project = ClientProject::find((int) $projectId);
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
            'client_project_id' => [
                'nullable', 'integer',
                Rule::exists('client_projects', 'id')->where('client_id', $this->route('client')?->id),
            ],
            'type' => ['required', new Enum(NextActionType::class)],
            'due_date' => ['required', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            // In-site: an agent when named, else the plan lands in the dispatch pool.
            'assigned_to' => $this->input('type') === NextActionType::InSiteVisit->value
                ? ['nullable', 'integer', new IsAgentUser]
                : ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
