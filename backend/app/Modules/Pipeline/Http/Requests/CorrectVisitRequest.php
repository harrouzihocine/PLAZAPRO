<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Correct a visit's details (type / unit / when / notes / checklist / outcome) with
 * a mandatory reason. Reassigning the agent stays with AssignVisit.
 *
 * Visit admins (visits.assign) may correct any visit; the assigned field agent may
 * also correct their OWN in-site logs (Phase-5 access rule) — the route carries no
 * permission middleware, this authorize() is the gate.
 */
class CorrectVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($user->can('visits.assign')) {
            return true;
        }

        $visit = $this->route('visit');

        return $visit instanceof Visit
            && $visit->type === VisitType::InSite
            && $visit->agent_id === $user->id
            && $user->can('visits.conduct');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'type' => ['required', new Enum(VisitType::class)],
            'unit_id' => ['required_if:type,in_site', 'nullable', 'integer', 'exists:units,id'],
            'scheduled_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            'outcome_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
        ];
    }
}
