<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Close ONE apartment on the deal — won with its own agreed price (it covers
 * the apartment and its boxes) or lost (released back to inventory). When the
 * LAST apartment closes lost and none was won, the deal resolves like a lost
 * deal always has: reopen the pipeline or archive the project (note required).
 * The closure desk (deals.manage).
 */
class CloseDealItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('deals.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'in:won,lost'],
            'agreed_price' => ['required_if:outcome,won', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'resolution' => ['nullable', 'in:reopen,archive'],
            'note' => ['required_if:resolution,archive', 'nullable', 'string', 'max:2000'],
            // Who gets credit for the sale (only on a win) — sale agents (the
            // marketing), in-site agents (site visits), and anyone else.
            'sale_agent_ids' => ['nullable', 'array'],
            'sale_agent_ids.*' => ['integer', 'exists:users,id'],
            'insite_agent_ids' => ['nullable', 'array'],
            'insite_agent_ids.*' => ['integer', 'exists:users,id'],
            'other_agent_ids' => ['nullable', 'array'],
            'other_agent_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
