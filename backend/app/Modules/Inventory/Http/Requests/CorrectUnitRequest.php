<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\SaleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Correct price and/or sale_status via HasVersions (cancel-and-duplicate). At
 * least one of the two must be present, plus a reason for the audit trail.
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
            'price' => ['required_without:sale_status', 'numeric', 'min:0', 'max:9999999999.99'],
            'sale_status' => ['required_without:price', new Enum(SaleStatus::class)],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
