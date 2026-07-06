<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a proposed instalment plan. Reconciliation to the deal's total_price
 * is enforced by the SaveSchedule Action (server is the source of truth), not here.
 */
class SaveScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('versements.record');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The won apartment the plan pays for (null only on legacy plans) —
            // its agreed price is what SaveSchedule reconciles against.
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'installments' => ['required', 'array', 'min:1'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }
}
