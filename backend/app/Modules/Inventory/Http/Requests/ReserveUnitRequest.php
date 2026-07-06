<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReserveUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('units.interest');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A hold may be tied to a deal; converting it then advances that deal
            // to `won` (see ConvertReservation).
            'client_project_id' => ['nullable', 'integer', 'exists:client_projects,id'],
        ];
    }
}
