<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Close an interested shortlisted property: won (with the agreed total price) or
 * lost. Deal management is the closure desk (deals.manage).
 */
class ShortlistOutcomeRequest extends FormRequest
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
            'total_price' => ['required_if:outcome,won', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
