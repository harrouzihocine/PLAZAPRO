<?php

declare(strict_types=1);

namespace App\Modules\Web\Actions;

use App\Modules\Clients\Actions\CreateClient;
use App\Modules\Clients\Actions\CreateClientProject;
use App\Modules\Clients\Models\Client;
use App\Modules\Settings\Models\User;
use App\Modules\Web\Enums\WebLeadStatus;
use App\Modules\Web\Models\WebLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turn a web lead into a real Client — composing the SAME actions the manual
 * flow uses (CreateClient / CreateClientProject), never the HTTP endpoint.
 * The phone-NSN duplicate guard mirrors ClientController::store: an existing
 * client with this phone blocks creation; the caller then either links the
 * lead to that client (when visible) or leaves it to the duplicate desk.
 *
 * Returns a result array the controller maps onto HTTP:
 *  - ['converted' => Client]                        → 200
 *  - ['duplicate' => Client|null, 'visible' => bool] → 409
 */
class ConvertWebLead
{
    public function __construct(
        private CreateClient $createClient,
        private CreateClientProject $createProject,
    ) {}

    public function handle(WebLead $lead, array $options, User $user): array
    {
        if ($lead->lead_status === WebLeadStatus::Converted) {
            throw ValidationException::withMessages(['lead' => 'This lead was already converted.']);
        }

        // Explicit link to an existing client (the duplicate follow-up path).
        if (($options['existing_client_id'] ?? null) !== null) {
            $client = Client::query()->active()
                ->visibleTo($user)
                ->findOrFail($options['existing_client_id']);

            $this->markConverted($lead, $client, $user);

            return ['converted' => $client];
        }

        // Same duplicate-phone guard as the manual "New client" flow.
        $existing = Client::query()->active()->matchingPhone($lead->phone)->first();

        if ($existing !== null) {
            $visible = Client::query()->visibleTo($user)->whereKey($existing->id)->exists();

            return ['duplicate' => $visible ? $existing : null, 'visible' => $visible];
        }

        return DB::transaction(function () use ($lead, $options, $user) {
            $client = $this->createClient->handle([
                'first_name' => $lead->name,
                'phone' => $lead->phone,
                // Only clients.manage may hand the client to an agent — the
                // same rule StoreClientRequest enforces on the manual flow.
                'assigned_agent_id' => $user->can('clients.manage')
                    ? ($options['assigned_agent_id'] ?? null)
                    : null,
                'notes' => $this->provenanceNotes($lead),
            ]);

            if (($options['create_project'] ?? false) && $lead->location_id !== null) {
                $this->createProject->handle($client, [
                    'location_id' => $lead->location_id,
                    'unit_id' => $lead->unit_id,
                ]);
            }

            $this->markConverted($lead, $client, $user);

            return ['converted' => $client];
        });
    }

    private function markConverted(WebLead $lead, Client $client, User $user): void
    {
        $lead->update([
            'lead_status' => WebLeadStatus::Converted->value,
            'converted_client_id' => $client->id,
            'handled_by' => $user->id,
            'handled_at' => now(),
        ]);
    }

    /** Where this client came from, kept on the client card. */
    private function provenanceNotes(WebLead $lead): string
    {
        $lines = ['Website lead ('.$lead->type->value.') — '.$lead->created_at?->format('Y-m-d H:i')];

        if ($lead->message !== null && $lead->message !== '') {
            $lines[] = 'Message: '.$lead->message;
        }

        if ($lead->preferred_date !== null) {
            $lines[] = 'Preferred visit: '.$lead->preferred_date->toDateString()
                .($lead->preferred_time ? ' '.$lead->preferred_time : '');
        }

        return implode("\n", $lines);
    }
}
