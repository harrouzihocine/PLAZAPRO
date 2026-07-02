<?php

declare(strict_types=1);

namespace App\Modules\Settings\Rules;

use App\Modules\Settings\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a user id refers to an active user who may follow a client up —
 * i.e. whose role can log calls (calls.log). This is the sales-agent population
 * (plus managers), as opposed to the field agents who only conduct visits. The
 * single source of truth for the client "assigned agent". Null passes so it can
 * sit beside `nullable`.
 */
class CanFollowUpClient implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $user = User::query()->with('role')->find($value);

        if (! $user || ! $user->is_active || ! $user->isActive() || ! $user->hasPermission('calls.log')) {
            $fail('The selected :attribute must be an active sales agent.');
        }
    }
}
