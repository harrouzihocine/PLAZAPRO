<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetUnitsAvailabilityRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ];
    }
}
