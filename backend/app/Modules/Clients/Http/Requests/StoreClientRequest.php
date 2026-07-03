<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Enums\IdDocumentType;
use App\Modules\Settings\Rules\CanFollowUpClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.create');
    }

    /**
     * Assigning a client to an agent is back-office-only (clients.manage). Drop
     * the field for anyone else (e.g. a sales agent capturing a lead) so it can't
     * be set via the API.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->user()?->can('clients.manage')) {
            $this->merge(['assigned_agent_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Names are optional — the phone is the one identifying field a
            // walk-in always gives. Nameless clients display as "No name".
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'source_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'rating_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            // Who told the client about the project (captured when source = referral).
            'referrer_name' => ['nullable', 'string', 'max:255'],
            'referrer_phone' => ['nullable', 'string', 'max:50'],
            'assigned_agent_id' => ['nullable', 'integer', new CanFollowUpClient],
            'notes' => ['nullable', 'string', 'max:5000'],
            // What the client is shopping for (property_interests items). Multi-select.
            'interests' => ['nullable', 'array'],
            'interests.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            // Identity / contract details, needed by the time a deal closes.
            // A client may present several ID documents; each carries its type,
            // number and issue date/place (تاريخ الإصدار و مكان الإصدار).
            'id_documents' => ['nullable', 'array'],
            'id_documents.*.type' => ['nullable', new Enum(IdDocumentType::class)],
            'id_documents.*.number' => ['nullable', 'string', 'max:100'],
            'id_documents.*.issued_at' => ['nullable', 'date'],
            'id_documents.*.issued_place' => ['nullable', 'string', 'max:255'],
            // Algerian national identification number (NIN) — not the ID-card number.
            'id_number' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
