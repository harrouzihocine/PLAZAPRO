<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('users.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('user')->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            // Only an admin (this request is gated by users.manage) may change a
            // username; the owner's own profile endpoint never touches it.
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._]+$/', Rule::unique('users', 'username')->ignore($id)],
            // Optional on update: only changed when a non-empty value is sent.
            'password' => ['nullable', 'string', Password::defaults()],
            // Still exactly one role.
            'role_id' => ['sometimes', 'required', 'integer', 'exists:roles,id'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
