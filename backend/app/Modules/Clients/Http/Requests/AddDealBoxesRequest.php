<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sell extra boxes onto a WON apartment (the client comes back for a parking /
 * storage box). `added_price` is the negotiated addition for ALL the picked
 * boxes together — omitted, their list prices apply. Back-office
 * (deals.manage), like the other money moves on a deal.
 */
class AddDealBoxesRequest extends FormRequest
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
            'box_ids' => ['required', 'array', 'min:1'],
            'box_ids.*' => ['integer', 'distinct', 'exists:boxes,id'],
            'added_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
