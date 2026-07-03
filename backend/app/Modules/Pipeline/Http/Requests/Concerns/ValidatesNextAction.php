<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests\Concerns;

use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Settings\Rules\IsAgentUser;
use Illuminate\Validation\Rules\Enum;

/**
 * The next-action rules shared by every request that completes an interaction
 * (logging a call, completing a visit). The `next_action` object is OPTIONAL —
 * some interactions genuinely end a thread, and a plan can be added later via
 * POST /clients/{client}/next-actions — but when one is sent it must be complete.
 *
 * When is captured as a required date + an OPTIONAL time (agents usually only know
 * the day). Who: a call/follow-up defaults to the client's sales agent (assigned_to
 * optional), while an in-site visit MUST be assigned to a field agent (is_agent).
 */
trait ValidatesNextAction
{
    /**
     * @return array<string, mixed>
     */
    protected function nextActionRules(): array
    {
        return [
            'next_action' => ['nullable', 'array'],
            'next_action.type' => ['required_with:next_action', new Enum(NextActionType::class)],
            'next_action.due_date' => ['required_with:next_action', 'date'],
            'next_action.due_time' => ['nullable', 'date_format:H:i'],
            'next_action.assigned_to' => $this->assignedToRules(),
        ];
    }

    /**
     * In-site (field) visits must be handed to an is_agent user; every other
     * next-action type may leave the assignee blank (it defaults to the client's
     * sales agent) or name any user.
     *
     * @return array<int, mixed>
     */
    private function assignedToRules(): array
    {
        if ($this->input('next_action.type') === NextActionType::InSiteVisit->value) {
            return ['required', 'integer', new IsAgentUser];
        }

        return ['nullable', 'integer', 'exists:users,id'];
    }
}
