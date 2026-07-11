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
            'type_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'contract_type_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            // Financing / payment options the project offers buyers
            // (`project_payment_methods` items) — a project may offer several.
            'payment_method_ids' => ['sometimes', 'nullable', 'array'],
            'payment_method_ids.*' => ['integer', 'exists:dynamic_list_items,id'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'expected_delivery_date' => ['sometimes', 'nullable', 'date'],
            'gtm_priority' => ['sometimes', new Enum(GtmPriority::class)],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'cover_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'cover_focus_x' => ['sometimes', 'integer', 'between:0,100'],
            'cover_focus_y' => ['sometimes', 'integer', 'between:0,100'],
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
