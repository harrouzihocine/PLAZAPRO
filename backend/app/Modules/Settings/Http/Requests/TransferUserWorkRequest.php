<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferUserWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('users.transfer');
    }

    /**
     * Shape only — whether the successor may actually RECEIVE each kind of
     * work (calls.log / projects.create / is_agent) depends on what the
     * leaver holds, so those checks live in TransferUserWork.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'successor_id' => [
                'required', 'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$this->route('user')?->id]),
            ],
            'dispatch_to_pool' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'successor_id.not_in' => 'The successor must be a different user.',
        ];
    }
}
