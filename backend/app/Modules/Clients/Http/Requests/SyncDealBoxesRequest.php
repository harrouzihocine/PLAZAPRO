<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-set the boxes riding with ONE apartment on an open deal (the agent adjusts
 * what the client takes alongside it). Availability and the link-to-apartment
 * rules are enforced in SyncDealUnitBoxes.
 */
class SyncDealBoxesRequest extends FormRequest
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
            'box_ids' => ['present', 'array'],
            'box_ids.*' => ['integer', 'distinct', 'exists:boxes,id'],
        ];
    }
}
