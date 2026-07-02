<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Settings\Rules\CanFollowUpClient;
use Illuminate\Foundation\Http\FormRequest;

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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'source_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'rating_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            'assigned_agent_id' => ['nullable', 'integer', new CanFollowUpClient],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
