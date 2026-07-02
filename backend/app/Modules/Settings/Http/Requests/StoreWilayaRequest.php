<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWilayaRequest extends FormRequest
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
        return [
            'code' => ['required', 'string', 'max:255', 'unique:wilayas,code'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
