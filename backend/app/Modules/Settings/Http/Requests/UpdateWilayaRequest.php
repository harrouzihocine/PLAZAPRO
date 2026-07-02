<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWilayaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('wilaya')->id;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('wilayas', 'code')->ignore($id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
