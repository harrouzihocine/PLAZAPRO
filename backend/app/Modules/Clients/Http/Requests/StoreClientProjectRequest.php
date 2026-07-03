<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Enums\ClientProjectStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Open a project. No first-call gate here: the workflow starts a NEW project
 * with its call log (the FE opens the call form as step one of "New project"),
 * so the project row must exist before that first call can attach to it.
 */
class StoreClientProjectRequest extends FormRequest
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
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'stage' => ['sometimes', new Enum(ClientProjectStage::class)],
            'total_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
