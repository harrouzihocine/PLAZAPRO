<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reactivating an archived project, optionally handing it to a chosen team. With
 * no body it is a plain reactivate; with handler_ids it becomes a hand-off (see
 * ReactivateProjectWithHandoff). `mode` picks how — as-is (same project) or a
 * separate new project — and is only required when the team actually changes
 * (enforced in the action, which knows the current contributors). `primary_id`
 * is the owner of the new project in `separate` mode and must be one of the team.
 */
class ReactivateClientProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('projects.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'handler_ids' => ['sometimes', 'array'],
            'handler_ids.*' => ['integer', 'exists:users,id'],
            'mode' => ['nullable', 'in:in_place,separate'],
            'primary_id' => ['nullable', 'integer', 'required_if:mode,separate', 'in_array:handler_ids.*'],
        ];
    }
}
