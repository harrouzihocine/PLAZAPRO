<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Close the WHOLE deal in one move (each apartment still resolves on its own
 * underneath — see CloseDealItemRequest for the one-apartment close):
 *  - won  → an agreed price per remaining apartment (covers its boxes);
 *  - lost → everything remaining is released; the resolution (reopen /
 *           archive, note required when archiving) only matters when nothing
 *           else carries the project — with another deal still open or won it
 *           is moot, so it is optional (defaults to reopen server-side).
 * The closure desk (deals.manage).
 */
class CloseDealRequest extends FormRequest
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
            'items' => ['required_if:outcome,won', 'array'],
            'items.*.item_id' => ['required', 'integer', 'exists:deal_items,id'],
            'items.*.agreed_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'resolution' => ['nullable', 'in:reopen,archive'],
            'note' => ['required_if:resolution,archive', 'nullable', 'string', 'max:2000'],
        ];
    }
}
