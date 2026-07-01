<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReserveUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('units.reserve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // client_projects arrives in Phase 3; until then this is an optional
            // reference with no exists:-check (the FK is added later).
            'client_project_id' => ['nullable', 'integer'],
        ];
    }
}
