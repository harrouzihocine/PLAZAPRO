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
            // An in-site plan may target specific apartment(s) — "same apartment"
            // (this visit's unit) or "another" (picked from the property picker).
            // Absent → the plan fans out over every shortlisted unit (legacy).
            'next_action.unit_ids' => ['nullable', 'array'],
            'next_action.unit_ids.*' => ['integer', 'distinct', 'exists:units,id'],
        ];
    }

    /**
     * An in-site (field) visit may only be handed to an is_agent user — but the
     * assignee is OPTIONAL: left blank, the plan lands in the dispatch pool and
     * the visits.dispatch holders assign it from the weekly board. Every other
     * type may leave the assignee blank (it defaults to the client's sales
     * agent) or name any user.
     *
     * @return array<int, mixed>
     */
    private function assignedToRules(): array
    {
        if ($this->input('next_action.type') === NextActionType::InSiteVisit->value) {
            return ['nullable', 'integer', new IsAgentUser];
        }

        return ['nullable', 'integer', 'exists:users,id'];
    }
}
