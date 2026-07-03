<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Set a deal's property shortlist at the office visit. At least one property is
 * required; each is a unit (apartment / local) or a box. Existence of the morph
 * target is re-checked in SyncShortlist.
 */
class SyncShortlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('visits.conduct');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'office_visit_id' => [
                'nullable', 'integer',
                Rule::exists('visits', 'id')->where('client_project_id', $this->route('project')?->id),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.shortlistable_type' => ['required', 'in:unit,box'],
            'items.*.shortlistable_id' => ['required', 'integer'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
