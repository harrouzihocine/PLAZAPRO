<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.duplicates.resolve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:deny,share_project,fork_project'],
            // Both project-based outcomes name the project: share_project joins it,
            // fork_project continues it (as a siloed new project).
            'project_id' => ['required_if:action,share_project,fork_project', 'integer', 'exists:client_projects,id'],
            'share_details' => ['boolean'],
        ];
    }
}
