<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Settings\Http\Requests\Concerns\ValidatesCommuneBelongsToWilaya;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreLocationRequest extends FormRequest
{
    use ValidatesCommuneBelongsToWilaya;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('locations.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:locations,code'],
            'wilaya_id' => ['nullable', 'integer', 'exists:wilayas,id'],
            'commune_id' => ['nullable', 'integer', 'exists:communes,id'],
            'contract_type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'expected_delivery_date' => ['nullable', 'date'],
            'gtm_priority' => ['sometimes', new Enum(GtmPriority::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCommuneMatchesWilaya($validator);
    }
}
