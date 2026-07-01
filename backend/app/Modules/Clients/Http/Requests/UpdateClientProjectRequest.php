<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a deal's particulars. `stage` is not accepted here — it moves through the
 * /advance endpoint so transition rules always apply.
 */
class UpdateClientProjectRequest extends FormRequest
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
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'total_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
