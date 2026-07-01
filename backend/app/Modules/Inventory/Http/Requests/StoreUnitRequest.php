<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

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
            'type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'floor_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'area_sqm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'rooms' => ['nullable', 'integer', 'min:0', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'sale_status' => ['sometimes', new Enum(SaleStatus::class)],
            'block' => ['nullable', 'string', 'max:255'],
            'stack_floor' => ['nullable', 'integer'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
