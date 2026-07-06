<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Rules\IsAgentUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add apartment(s) to visit on a project, standalone (not inside a completion).
 * Conducting agents on a project they can see may plan field visits — a field
 * agent dispatched here, the client's sales agent, or any conductor. The route
 * carries no permission middleware, so this authorize() is the whole gate.
 */
class ProposeInSiteVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('visits.conduct')) {
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
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'distinct', 'exists:units,id'],
            'due_date' => ['required', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            // Only a real field agent may be pre-picked (dispatchers only, applied
            // in the controller); everyone else's addition lands in the pool.
            'assigned_to' => ['nullable', 'integer', new IsAgentUser],
        ];
    }
}
