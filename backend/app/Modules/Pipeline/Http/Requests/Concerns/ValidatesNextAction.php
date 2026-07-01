<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests\Concerns;

use App\Modules\Pipeline\Enums\NextActionType;
use Illuminate\Validation\Rules\Enum;

/**
 * The enforced-next-action rule, shared by every request that completes an
 * interaction (logging a call, completing a visit). The `next_action` object is
 * required — this is the trust-boundary half of the "pipeline never goes cold" rule.
 */
trait ValidatesNextAction
{
    /**
     * @return array<string, mixed>
     */
    protected function nextActionRules(): array
    {
        return [
            'next_action' => ['required', 'array'],
            'next_action.type' => ['required', new Enum(NextActionType::class)],
            'next_action.due_at' => ['required', 'date'],
            'next_action.assigned_to' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
