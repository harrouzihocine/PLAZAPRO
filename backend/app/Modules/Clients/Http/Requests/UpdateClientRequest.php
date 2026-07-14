<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Enums\IdDocumentType;
use App\Modules\Settings\Http\Requests\Concerns\ValidatesCommuneBelongsToWilaya;
use App\Modules\Settings\Rules\CanFollowUpClient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Edit a client (clients.edit), including (re)assigning the agent — that one
 * field stays back-office-only (clients.manage). Every field is `sometimes`
 * so a lean partial update (e.g. just assigned_agent_id) is accepted.
 */
class UpdateClientRequest extends FormRequest
{
    use ValidatesCommuneBelongsToWilaya;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.edit');
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
            // Reassigning the follow-up agent is clients.manage territory. With
            // no rule the field never reaches validated(), so a clients.edit-only
            // user (whose form echoes null — ownership is masked for them) can
            // neither clear nor change the agent.
            ...($this->user()?->can('clients.manage') ? [
                'assigned_agent_id' => ['sometimes', 'nullable', 'integer', new CanFollowUpClient($this->route('client')?->assigned_agent_id)],
            ] : []),
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'id_documents' => ['sometimes', 'nullable', 'array'],
            'id_documents.*.type' => ['nullable', new Enum(IdDocumentType::class)],
            'id_documents.*.number' => ['nullable', 'string', 'max:100'],
            'id_documents.*.issued_at' => ['nullable', 'date'],
            'id_documents.*.issued_place' => ['nullable', 'string', 'max:255'],
            'id_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            // A birth date must not be in the future; today itself is valid (a
            // newborn). `before:today` wrongly rejected today — use before_or_equal.
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Wilaya + commune of residence (optional); the commune must belong
            // to its wilaya — enforced in withValidator().
            'wilaya_id' => ['sometimes', 'nullable', 'integer', 'exists:wilayas,id'],
            'commune_id' => ['sometimes', 'nullable', 'integer', 'exists:communes,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCommuneMatchesWilaya($validator);
    }
}
