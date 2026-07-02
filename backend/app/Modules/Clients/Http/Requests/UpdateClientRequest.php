<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Settings\Rules\CanFollowUpClient;
use Illuminate\Foundation\Http\FormRequest;

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
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'source_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'rating_id' => ['sometimes', 'nullable', 'integer', 'exists:dynamic_list_items,id'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', new CanFollowUpClient],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
