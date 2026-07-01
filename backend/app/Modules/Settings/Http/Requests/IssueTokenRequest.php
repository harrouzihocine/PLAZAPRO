<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Credentials for a stateless Bearer-token login (mobile / external clients).
 * Same shape as LoginRequest plus a device label so tokens are per-device.
 */
class IssueTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // open (throttled); credentials are checked in the controller
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
