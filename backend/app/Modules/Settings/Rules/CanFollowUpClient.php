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
 *
 * On edit, pass the client's currently-stored agent id as `$current`: re-submitting
 * an unchanged assignment always passes. Otherwise editing any unrelated field would
 * 422 whenever the already-assigned agent has since lost calls.log or been
 * deactivated — a validation error the user cannot act on and did not cause.
 */
class CanFollowUpClient implements ValidationRule
{
    public function __construct(private readonly ?int $current = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        // An unchanged assignment is never re-validated — only a real CHANGE is.
        if ($this->current !== null && (int) $value === $this->current) {
            return;
        }

        $user = User::query()->with('role')->find($value);

        if (! $user || ! $user->is_active || ! $user->isActive() || ! $user->hasPermission('calls.log')) {
            $fail('The selected :attribute must be an active sales agent.');
        }
    }
}
