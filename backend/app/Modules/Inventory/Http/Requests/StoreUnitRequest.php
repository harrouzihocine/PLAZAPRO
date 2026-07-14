<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Enums\SaleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreUnitRequest extends FormRequest
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
        $locationId = $this->route('location')->id;

        return [
            'reference' => [
                'required', 'string', 'max:255',
                // Unique among the location's live (active) units only.
                Rule::unique('units', 'reference')
                    ->where('location_id', $locationId)
                    ->where('status', 'active'),
            ],
            'room_number_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'floor_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'area_sqm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            // Two finish-level prices — a unit must quote at least one.
            'price_semi_fini' => ['nullable', 'required_without:price_fini', 'numeric', 'min:0', 'max:9999999999.99'],
            'price_fini' => ['nullable', 'required_without:price_semi_fini', 'numeric', 'min:0', 'max:9999999999.99'],
            'sale_status' => ['sometimes', new Enum(SaleStatus::class)],
            'block' => ['nullable', 'string', 'max:255'],
            'stack_floor' => ['nullable', 'integer'],
            'position' => ['nullable', 'integer'],
            'gtm_priority' => ['sometimes', new Enum(GtmPriority::class)],
            // Payment options: off = inherit the project's, on = the unit's own
            // set below (`project_payment_methods` items — e.g. cash-only).
            'payment_methods_overridden' => ['sometimes', 'boolean'],
            'payment_method_ids' => ['nullable', 'array'],
            'payment_method_ids.*' => ['integer', 'exists:dynamic_list_items,id'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
