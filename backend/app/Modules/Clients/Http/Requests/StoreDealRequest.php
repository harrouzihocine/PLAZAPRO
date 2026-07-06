<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Models\ClientProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Open the deal on a project from the properties the client wants. The visiting
 * agent creates it from a visit log (visits.conduct); a direct deal (no visit_id)
 * additionally needs deals.direct — enforced in CreateDeal, where the provenance
 * is checked against the project. The project must also be VISIBLE to the actor:
 * the permission alone must not let an agent open a deal (and place holds) on a
 * project they cannot see — same gate as ProposeInSiteVisitRequest.
 */
class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! ($user->can('visits.conduct') || $user->can('deals.direct'))) {
            return false;
        }

        $project = $this->route('project');

        return $project instanceof ClientProject && $project->isVisibleTo($user);
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
            // Boxes ride along per apartment: the SPECIFIC boxes to take (each
            // is linked to the apartment — CreateDeal enforces the link rules).
            'units.*.box_ids' => ['nullable', 'array'],
            'units.*.box_ids.*' => ['integer', 'distinct', 'exists:boxes,id'],
        ];
    }
}
