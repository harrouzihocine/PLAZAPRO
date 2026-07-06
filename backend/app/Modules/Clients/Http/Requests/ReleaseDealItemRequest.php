<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Release a WON apartment on a deal — the sale fell through. The reversal puts
 * the apartment (and its boxes) back on the market; recorded payments stay as
 * history (refundable one by one). When nothing won remains on the project the
 * resolution applies: reopen the pipeline (default) or archive the project
 * (note required). The closure desk (deals.manage), like the close endpoints.
 */
class ReleaseDealItemRequest extends FormRequest
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
            'resolution' => ['nullable', 'in:reopen,archive'],
            'note' => ['required_if:resolution,archive', 'nullable', 'string', 'max:2000'],
        ];
    }
}
