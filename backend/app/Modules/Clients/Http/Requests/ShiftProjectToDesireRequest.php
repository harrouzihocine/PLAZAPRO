<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Settings\Http\Requests\Concerns\ValidatesCommuneBelongsToWilaya;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shift a deal back to the Desire list (client changed their mind). Carries the
 * desire criteria (same fields as UpsertDesire) to re-capture what the client wants.
 * Deal lifecycle is back-office (clients.manage).
 */
class ShiftProjectToDesireRequest extends FormRequest
{
    use ValidatesCommuneBelongsToWilaya;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.manage');
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
    }
}
