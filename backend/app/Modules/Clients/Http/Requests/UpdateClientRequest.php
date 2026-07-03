<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Enums\IdDocumentType;
use App\Modules\Settings\Rules\CanFollowUpClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Edit a client, including (re)assigning the agent. Every field is `sometimes`
 * so a lean partial update (e.g. just assigned_agent_id) is accepted.
 */
class UpdateClientRequest extends FormRequest
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
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'source_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'rating_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'referrer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'referrer_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', new CanFollowUpClient],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'interests' => ['sometimes', 'nullable', 'array'],
            'interests.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            'id_document_type' => ['sometimes', 'nullable', new Enum(IdDocumentType::class)],
            'id_document_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
