<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Open a project. No first-call gate here: the workflow starts a NEW project
 * with its call log (the FE opens the call form as step one of "New project"),
 * so the project row must exist before that first call can attach to it.
 *
 * Gated by projects.create (held by agents alongside clients.create), not
 * projects.manage — opening a deal for a client you brought in is the agent's
 * job. The user must also be able to SEE the client (visibleTo), so an agent
 * cannot open a project on a colleague's out-of-scope client.
 */
class StoreClientProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $client = $this->route('client');

        return $user?->can('projects.create')
            && $client instanceof Client
            && Client::query()->visibleTo($user)->whereKey($client->id)->exists();
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
