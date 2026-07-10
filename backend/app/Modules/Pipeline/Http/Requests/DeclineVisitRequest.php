<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Declining an assigned in-site visit: only the assigned agent, only while the
 * visit is open, and never without saying why — the dispatcher re-assigns on
 * that reason.
 */
class DeclineVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Visit $visit */
        $visit = $this->route('visit');

        return (int) $visit->agent_id === (int) $this->user()->id
            && $visit->type->value === 'in_site'
            && $visit->completed_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
