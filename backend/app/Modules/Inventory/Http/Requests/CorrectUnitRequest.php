<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\SaleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Correct the prices and/or sale_status via HasVersions (cancel-and-duplicate).
 * At least one of the fields must be present, plus a reason for the audit
 * trail. A price sent as null REMOVES that finish offer — but the corrected
 * unit must still quote at least one price (mirrors the DB check).
 */
class CorrectUnitRequest extends FormRequest
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
        return [
            'price_semi_fini' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'price_fini' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'sale_status' => ['sometimes', new Enum(SaleStatus::class)],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->exists('price_semi_fini') && ! $this->exists('price_fini') && ! $this->exists('sale_status')) {
                $validator->errors()->add('price_semi_fini', __('app.unit_correction_empty'));

                return;
            }

            $unit = $this->route('unit');

            // The unit AFTER the correction: sent fields override, absent ones keep.
            $semi = $this->exists('price_semi_fini') ? $this->input('price_semi_fini') : $unit?->price_semi_fini;
            $fini = $this->exists('price_fini') ? $this->input('price_fini') : $unit?->price_fini;

            if ($semi === null && $fini === null) {
                $validator->errors()->add('price_semi_fini', __('app.unit_needs_one_price'));
            }
        });
    }
}
