<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Log a phone call and its **required** next action in one transaction. The next
 * action is validated in the FormRequest and re-checked here (defense in depth):
 * a call can never be logged without leaving a next step.
 */
class LogCall
{
    public function __construct(private CreateNextAction $createNextAction) {}

    public function handle(Client $client, array $data, User $actor): Call
    {
        abort_if(empty($data['next_action']), 422, 'A next action is required when logging a call.');

        return DB::transaction(function () use ($client, $data, $actor) {
            $call = Call::create([
                'client_id' => $client->id,
                'client_project_id' => $data['client_project_id'] ?? null,
                'agent_id' => $data['agent_id'] ?? $actor->id,
                'direction' => $data['direction'],
                'outcome_id' => $data['outcome_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'called_at' => $data['called_at'] ?? now(),
            ]);

            // The action belongs to the deal if one is linked, otherwise the client.
            $subject = ! empty($data['client_project_id'])
                ? ClientProject::findOrFail($data['client_project_id'])
                : $client;

            $this->createNextAction->handle($subject, $call, $data['next_action']);

            // Return the created instance (not a refetch) so the API responds 201.
            return $call;
        });
    }
}
