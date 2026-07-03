<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Http\Requests\Concerns\ValidatesNextAction;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;

class CompleteVisitRequest extends FormRequest
{
    use ValidatesNextAction;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('visits.conduct')) {
            return false;
        }

        // Phase-5 access rule: an in-site (field) log belongs to its assigned
        // agent — only they, or a visit administrator, may complete it.
        $visit = $this->route('visit');
        if ($visit instanceof Visit && $visit->type === VisitType::InSite) {
            return $visit->agent_id === $user->id || $user->can('visits.assign');
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'outcome_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Fast checkbox checklist (office_visit_checklist / insite_outcomes ids).
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            // Office visits may re-set the deal's shortlist on completion (full sync
            // — absence keeps the current list; ≥1 enforced in CompleteInteraction).
            'shortlist' => ['nullable', 'array'],
            'shortlist.*.shortlistable_type' => ['required', 'in:unit,box'],
            'shortlist.*.shortlistable_id' => ['required', 'integer'],
            'shortlist.*.note' => ['nullable', 'string', 'max:1000'],
        ], $this->nextActionRules());
    }
}
