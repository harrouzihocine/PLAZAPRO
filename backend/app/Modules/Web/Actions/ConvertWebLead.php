<?php

declare(strict_types=1);

namespace App\Modules\Web\Actions;

use App\Modules\Clients\Actions\CreateClient;
use App\Modules\Clients\Actions\CreateClientProject;
use App\Modules\Clients\Actions\UpsertDesire;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\Desire;
use App\Modules\Settings\Models\User;
use App\Modules\Web\Enums\WebLeadStatus;
use App\Modules\Web\Models\WebLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turn a web lead into a real Client — composing the SAME actions the manual
 * flow uses (CreateClient / CreateClientProject / UpsertDesire), never the
 * HTTP endpoint. The phone-NSN duplicate guard mirrors ClientController::store:
 * an existing client with this phone blocks creation; the caller then either
 * links the lead to that client (when visible) or leaves it to the duplicate
 * desk. A desire lead's criteria become the client's real Desire, so the
 * Matches board picks the client up with no re-typing.
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
        private UpsertDesire $upsertDesire,
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

            return DB::transaction(function () use ($lead, $client, $user) {
                $this->captureDesire($lead, $client);
                $this->markConverted($lead, $client, $user);

                return ['converted' => $client];
            });
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

            $this->captureDesire($lead, $client);
            $this->markConverted($lead, $client, $user);

            return ['converted' => $client];
        });
    }

    /**
     * A desire lead's criteria become the client's client-level Desire — the
     * Matches board then surfaces this client the moment inventory fits.
     * Never clobbers a profile an agent already captured: only a blank slate
     * (or a closed-out desire UpsertDesire revives) is written; skipped
     * criteria still reach the client card via provenanceNotes.
     */
    private function captureDesire(WebLead $lead, Client $client): void
    {
        $criteria = $lead->criteria;

        if ($criteria === null) {
            return;
        }

        $hasActiveDesire = Desire::query()->active()
            ->where('client_id', $client->id)
            ->whereNull('client_project_id')
            ->exists();

        if ($hasActiveDesire) {
            return;
        }

        $this->upsertDesire->handle($client, [
            'wilaya_ids' => $criteria['wilaya_ids'] ?? [],
            'commune_ids' => $criteria['commune_ids'] ?? [],
            'type_ids' => $criteria['type_ids'] ?? [],
            'room_number_ids' => $criteria['room_number_ids'] ?? [],
            'budget_min' => $criteria['budget_min'] ?? null,
            'budget_max' => $criteria['budget_max'] ?? null,
            'notes' => $this->desireNotes($lead),
        ]);
    }

    /** The desire's required story: where it came from + the visitor's words. */
    private function desireNotes(WebLead $lead): string
    {
        $lines = ['Website desire request — '.$lead->created_at?->format('Y-m-d H:i')];

        if ($lead->message !== null && $lead->message !== '') {
            $lines[] = $lead->message;
        }

        return implode("\n", $lines);
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

        // The desire criteria in the converter's language — readable even when
        // the structured Desire was skipped (client already had one).
        foreach ($lead->resolvedCriteria() ?? [] as $key => $value) {
            $lines[] = $key.': '.(is_array($value) ? implode(', ', $value) : number_format((float) $value, 0, '.', ' '));
        }

        if ($lead->preferred_date !== null) {
            $lines[] = 'Preferred visit: '.$lead->preferred_date->toDateString()
                .($lead->preferred_time ? ' '.$lead->preferred_time : '');
        }

        return implode("\n", $lines);
    }
}
