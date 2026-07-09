<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportUnitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('units.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // txt: Excel sometimes saves "CSV" with a .txt extension.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }
}
