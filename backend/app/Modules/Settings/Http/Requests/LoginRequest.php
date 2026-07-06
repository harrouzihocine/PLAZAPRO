<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // login is open (throttled); credentials are checked in the controller
    }

    public function rules(): array
    {
        return [
            // A single identifier: either the email or the username.
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
