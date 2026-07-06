<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Settings\Rules\CanFollowUpClient;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Delegate a waiting client (a desire match) to the sales agent who will run the
 * reconnect. Gated by clients.manage — the same grant that governs the client's
 * assigned agent everywhere else — so this is a manager's triage action, not
 * something an agent does to their peers. The assignee is validated against the
 * shared CanFollowUpClient rule (an active user who can log calls, i.e. a sales
 * agent who can carry the lead from call to project).
 */
class AssignClientAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_id' => ['required', 'integer', new CanFollowUpClient],
        ];
    }
}
