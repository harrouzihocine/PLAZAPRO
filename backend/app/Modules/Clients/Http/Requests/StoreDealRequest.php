<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Open the deal on a project from the properties the client wants. The visiting
 * agent creates it from a visit log (visits.conduct); a direct deal (no visit_id)
 * additionally needs deals.direct — enforced in CreateDeal, where the provenance
 * is checked against the project.
 */
class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('visits.conduct') || $user->can('deals.direct'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visit_id' => ['nullable', 'integer', 'exists:visits,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'units' => ['required', 'array', 'min:1'],
            'units.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            // Boxes ride along per apartment: how many to include (0 = none).
            'units.*.box_count' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
