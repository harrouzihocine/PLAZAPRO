<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Close the deal won (with the agreed total price — prefilled client-side from
 * the reserved property prices) or lost. Back-office (clients.manage).
 */
class CloseDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.manage');
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
