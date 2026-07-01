<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Settings\Rules\IsAgentUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Scheduling a visit assigns it to an agent, so it requires visits.assign and the
 * agent-only rule. Apartment visits must name a unit.
 */
class ScheduleVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('visits.assign');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'client_project_id' => ['nullable', 'integer', 'exists:client_projects,id'],
            'type' => ['required', new Enum(VisitType::class)],
            'unit_id' => ['required_if:type,apartment', 'nullable', 'integer', 'exists:units,id'],
            'agent_id' => ['required', 'integer', new IsAgentUser],
            'scheduled_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
