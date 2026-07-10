<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A dispatcher's verdict on a beyond-window office-visit plan. Deny must say
 * why (the reason rides the agent's notification and the cancelled plan's
 * trail); reschedule must say when. The route is already gated by
 * can:visits.dispatch — authorize() restates it so the request stands alone.
 */
class DecideOfficeVisitApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('visits.dispatch') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'deny', 'reschedule'])],
            'reason' => ['nullable', 'required_if:decision,deny', 'string', 'max:500'],
            'due_date' => ['nullable', 'required_if:decision,reschedule', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
        ];
    }
}
