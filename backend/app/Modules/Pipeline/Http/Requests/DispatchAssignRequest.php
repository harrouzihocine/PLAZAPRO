<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Settings\Rules\IsAgentUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A batch of dispatch-board moves, saved together. Each change targets an
 * in-site plan ('action') or a materialized field visit ('visit'); a null /
 * absent agent returns the item to the pending pool. Dates are re-checked
 * (today onwards) in AssignDispatchItem — the trust boundary here only shapes
 * the payload.
 */
class DispatchAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('visits.dispatch');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.kind' => ['required', 'in:action,visit'],
            'changes.*.id' => ['required', 'integer'],
            'changes.*.agent_id' => ['nullable', 'integer', new IsAgentUser],
            'changes.*.due_date' => ['nullable', 'date'],
        ];
    }
}
