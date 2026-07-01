<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\SaleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateBoxRequest extends FormRequest
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
        $box = $this->route('box');

        return [
            'reference' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('boxes', 'reference')
                    ->where('location_id', $box->location_id)
                    ->where('status', 'active')
                    ->ignore($box->id),
            ],
            'type_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'sale_status' => ['sometimes', new Enum(SaleStatus::class)],
            'unit_id' => [
                'sometimes', 'nullable',
                Rule::exists('units', 'id')->where('location_id', $box->location_id),
            ],
        ];
    }
}
