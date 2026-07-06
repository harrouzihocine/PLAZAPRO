<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * A user editing their OWN profile. Authorization is implicit — the route is
 * behind auth:sanctum and always targets the authenticated user, so there is no
 * per-user check. Changing the password requires re-entering the current one.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Optional: only changes when a new one is supplied, and only with
            // the correct current password (guards a hijacked open session).
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'current_password' => ['required_with:password', 'current_password'],
        ];
    }
}
