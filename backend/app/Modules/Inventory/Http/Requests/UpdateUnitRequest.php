<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\GtmPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

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
            'room_number_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'floor_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'area_sqm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'block' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stack_floor' => ['sometimes', 'nullable', 'integer'],
            'position' => ['sometimes', 'nullable', 'integer'],
            'gtm_priority' => ['sometimes', new Enum(GtmPriority::class)],
            // Payment options: off = inherit the project's, on = the unit's own
            // set below (`project_payment_methods` items — e.g. cash-only).
            'payment_methods_overridden' => ['sometimes', 'boolean'],
            'payment_method_ids' => ['sometimes', 'nullable', 'array'],
            'payment_method_ids.*' => ['integer', 'exists:dynamic_list_items,id'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
