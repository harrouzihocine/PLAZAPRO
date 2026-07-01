<?php

declare(strict_types=1);

namespace App\Modules\Settings\Rules;

use App\Modules\Settings\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a user id refers to an active user whose role is flagged as an
 * agent (is_agent). The single source of truth for "agent-only" assignment —
 * reused by clients (assigned_agent_id) and visits (agent_id). Null passes so it
 * can sit beside `nullable`; require the field separately when assignment is
 * mandatory.
 */
class IsAgentUser implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $user = User::query()->with('role')->find($value);

        if (! $user || ! $user->is_active || ! $user->isActive() || ! $user->isAgent()) {
            $fail('The selected :attribute must be an active agent.');
        }
    }
}
