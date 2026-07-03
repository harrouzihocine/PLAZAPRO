<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Clients\Http\Requests\Concerns\RequiresFirstCall;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Settings\Rules\IsAgentUser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Scheduling a visit assigns it to an agent, so it requires visits.assign and the
 * agent-only rule. In-site (field) visits must name a unit.
 */
class ScheduleVisitRequest extends FormRequest
{
    use RequiresFirstCall;

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
            // A linked deal must belong to the visit's client.
            'client_project_id' => [
                'nullable', 'integer',
                Rule::exists('client_projects', 'id')->where('client_id', $this->input('client_id')),
            ],
            'type' => ['required', new Enum(VisitType::class)],
            'unit_id' => ['required_if:type,in_site', 'nullable', 'integer', 'exists:units,id'],
            'agent_id' => ['required', 'integer', new IsAgentUser],
            'scheduled_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireFirstCall($validator, $this->input('client_id'), 'scheduling a visit');
    }
}
