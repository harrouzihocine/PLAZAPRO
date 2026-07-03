<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Settings\Http\Requests\Concerns\ValidatesCommuneBelongsToWilaya;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateLocationRequest extends FormRequest
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
        $id = $this->route('location')->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('locations', 'code')->ignore($id)],
            'wilaya_id' => ['sometimes', 'nullable', 'integer', 'exists:wilayas,id'],
            'commune_id' => ['sometimes', 'nullable', 'integer', 'exists:communes,id'],
            'contract_type_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'expected_delivery_date' => ['sometimes', 'nullable', 'date'],
            'gtm_priority' => ['sometimes', new Enum(GtmPriority::class)],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCommuneMatchesWilaya($validator);
    }
}
