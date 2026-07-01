<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectVersementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('versements.cancel');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'paid_on' => ['required', 'date'],
            'method_id' => ['required', 'integer', 'exists:dynamic_list_items,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
