<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ordinary spec edits. price and sale_status are intentionally NOT accepted here
 * — corrections to those go through the /correct endpoint (HasVersions).
 */
class UpdateUnitRequest extends FormRequest
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
        $unit = $this->route('unit');

        return [
            'reference' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('units', 'reference')
                    ->where('location_id', $unit->location_id)
                    ->where('status', 'active')
                    ->ignore($unit->id),
            ],
            'type_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'floor_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'area_sqm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'block' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stack_floor' => ['sometimes', 'nullable', 'integer'],
            'position' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
