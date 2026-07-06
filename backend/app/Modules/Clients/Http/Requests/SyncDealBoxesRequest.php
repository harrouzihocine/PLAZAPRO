<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-set the boxes riding with ONE apartment on an open deal (the agent adjusts
 * what the client takes alongside it). Availability and the link-to-apartment
 * rules are enforced in SyncDealUnitBoxes. The deal's project must be VISIBLE to
 * the actor: the permission alone must not let an agent edit a deal on a project
 * they cannot see.
 */
class SyncDealBoxesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('visits.conduct')) {
            return false;
        }

        $deal = $this->route('deal');

        return $deal instanceof Deal && $deal->clientProject->isVisibleTo($user);
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
