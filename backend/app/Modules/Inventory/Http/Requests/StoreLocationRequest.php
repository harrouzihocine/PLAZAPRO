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
            'type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'contract_type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            // Financing / payment options the project offers buyers
            // (`project_payment_methods` items) — a project may offer several.
            'payment_method_ids' => ['nullable', 'array'],
            'payment_method_ids.*' => ['integer', 'exists:dynamic_list_items,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'expected_delivery_date' => ['nullable', 'date'],
            'gtm_priority' => ['sometimes', new Enum(GtmPriority::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            // Public-website controls (showcase visibility + marketing copy).
            'is_published' => ['sometimes', 'boolean'],
            'show_prices' => ['sometimes', 'boolean'],
            'show_availability' => ['sometimes', 'boolean'],
            'marketing_tagline' => ['sometimes', 'nullable', 'array'],
            'marketing_tagline.en' => ['nullable', 'string', 'max:180'],
            'marketing_tagline.fr' => ['nullable', 'string', 'max:180'],
            'marketing_tagline.ar' => ['nullable', 'string', 'max:180'],
            'marketing_description' => ['sometimes', 'nullable', 'array'],
            'marketing_description.en' => ['nullable', 'string', 'max:5000'],
            'marketing_description.fr' => ['nullable', 'string', 'max:5000'],
            'marketing_description.ar' => ['nullable', 'string', 'max:5000'],
            'construction_progress' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCommuneMatchesWilaya($validator);
    }
}
