<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\SaleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreBoxRequest extends FormRequest
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
        $location = $this->route('location');

        return [
            'reference' => [
                'required', 'string', 'max:255',
                Rule::unique('boxes', 'reference')
                    ->where('location_id', $location->id)
                    ->where('status', 'active'),
            ],
            'type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'sale_status' => ['sometimes', new Enum(SaleStatus::class)],
            'unit_id' => [
                'nullable',
                // The linked unit must belong to the same location.
                Rule::exists('units', 'id')->where('location_id', $location->id),
            ],
        ];
    }
}
