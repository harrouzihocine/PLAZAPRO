<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Settings\Http\Requests\Concerns\ValidatesCommuneBelongsToWilaya;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Capturing/updating a client's desire is part of qualifying a lead, so it is
 * gated by clients.create (which agents hold), not clients.manage.
 */
class UpsertDesireRequest extends FormRequest
{
    use ValidatesCommuneBelongsToWilaya;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wilaya_id' => ['nullable', 'integer', 'exists:wilayas,id'],
            'commune_id' => ['nullable', 'integer', 'exists:communes,id'],
            'type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'floor_pref' => ['nullable', 'string', 'max:255'],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCommuneMatchesWilaya($validator);

        // Enforce ordering only when both bounds are actually provided.
        $validator->after(function (Validator $v) {
            $min = $this->input('budget_min');
            $max = $this->input('budget_max');
            if ($min !== null && $max !== null && (float) $max < (float) $min) {
                $v->errors()->add('budget_max', 'The maximum budget must be greater than or equal to the minimum budget.');
            }
        });
    }
}
