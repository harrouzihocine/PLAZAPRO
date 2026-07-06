<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Http\Requests\Concerns\ValidatesDesireFields;
use App\Modules\Clients\Models\Deal;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Http\Requests\Concerns\ValidatesClosure;
use App\Modules\Pipeline\Http\Requests\Concerns\ValidatesNextAction;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;

class CompleteVisitRequest extends FormRequest
{
    use ValidatesClosure;
    use ValidatesDesireFields;
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

        // A field agent dispatched to this project (and nothing more — not a
        // contributor, not the client's own agent) is here for the in-site visit
        // only: they may not complete the project's OFFICE visits. A visit
        // administrator (visits.assign) is exempt.
        if ($visit instanceof Visit
            && $visit->type === VisitType::Office
            && $visit->client_project_id !== null
            && ! $user->can('visits.assign')
            && $visit->clientProject?->isDispatchOnlyAgent($user)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = array_merge([
            'outcome_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Fast checkbox checklist (office_visit_checklist / insite_outcomes ids).
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            // Concerns/objections raised (objection_reasons item ids) — mined by
            // the Voice-of-Client analytics (in-site objections attribute to the unit).
            'objections' => ['nullable', 'array'],
            'objections.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            // Office visits may re-set the deal's shortlist on completion (full sync
            // — absence keeps the current list; ≥1 enforced in CompleteInteraction).
            'shortlist' => ['nullable', 'array'],
            'shortlist.*.shortlistable_type' => ['required', 'in:unit,box'],
            'shortlist.*.shortlistable_id' => ['required', 'integer'],
            'shortlist.*.note' => ['nullable', 'string', 'max:1000'],
        ], $this->nextActionRules(), $this->closureRules(), $this->desireFieldRules('closure.desire'));

        // An interim in-site visit (the project still has open sibling visits)
        // records only its result — the self-closing rule (a next action OR a
        // closure) is enforced once, on the LAST remaining visit. Same when the
        // project's conclusion already exists (a deal is open or won on it):
        // the remaining visits must stay completable as plain records, so no
        // "Scheduled" log lingers once the deal decided the thread.
        if ($this->isInterimInSite() || $this->projectHasSettledDeal()) {
            $rules['closure'] = ['nullable', 'array', 'prohibits:next_action'];
        }

        return $rules;
    }

    /**
     * The visit's project already carries an open or won deal — the
     * thread has its conclusion, so completing this visit needs no closure of
     * its own (though a `deal` closure may still open another deal).
     */
    private function projectHasSettledDeal(): bool
    {
        $visit = $this->route('visit');

        if (! $visit instanceof Visit || $visit->client_project_id === null) {
            return false;
        }

        return Deal::query()->active()
            ->where('client_project_id', $visit->client_project_id)
            ->whereIn('state', [DealState::Open->value, DealState::Won->value])
            ->exists();
    }

    /**
     * True when the visit being completed is an in-site visit whose project still
     * has another open in-site visit — i.e. this is not the last one to fill.
     */
    private function isInterimInSite(): bool
    {
        $visit = $this->route('visit');

        if (! $visit instanceof Visit || $visit->type !== VisitType::InSite || $visit->client_project_id === null) {
            return false;
        }

        return Visit::query()->active()
            ->where('client_project_id', $visit->client_project_id)
            ->where('type', VisitType::InSite->value)
            ->whereNull('completed_at')
            ->whereKeyNot($visit->getKey())
            ->exists();
    }
}
