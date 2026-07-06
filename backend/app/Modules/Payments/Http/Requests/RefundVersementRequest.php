<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refund a done payment (the money went back to the client). The record stays
 * in history flagged refunded; only the reason travels. Gated like corrections
 * (versements.cancel) — both are money reversals.
 */
class RefundVersementRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
