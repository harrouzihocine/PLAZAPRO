<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Actions\AddShortlistItems;
use App\Modules\Clients\Actions\EnsureActiveClientProject;
use App\Modules\Clients\Actions\ReactivateClientProject;
use App\Modules\Clients\Actions\UpsertDesire;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Log a phone call — and, when one is planned, its next action — in one
 * transaction. The next action is optional: some calls genuinely end a thread
 * (a plan can still be added later via POST /clients/{client}/next-actions).
 *
 * Qualification happens on the call, so the payload may also carry:
 *  - `properties` (Branch B — matching inventory): added to the deal's shortlist;
 *    the client's open deal is auto-created at `lead` when none exists yet;
 *  - `desire` (Branch A — no match): the desire profile, upserted atomically.
 *
 * Desire-list reopen: a project shifted to the desire list is closed to any new
 * log until a NEW call reconnects the client (typically after a desire match) —
 * that call reactivates the project and attaches to it.
 */
class LogCall
{
    public function __construct(
        private CreateNextAction $createNextAction,
        private ClosePendingNextActions $closePendingNextActions,
        private SyncVisitFromNextAction $syncVisitFromNextAction,
        private EnsureActiveClientProject $ensureActiveClientProject,
        private ReactivateClientProject $reactivateClientProject,
        private AddShortlistItems $addShortlistItems,
        private UpsertDesire $upsertDesire,
    ) {}

    public function handle(Client $client, array $data, User $actor): Call
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            // The call belongs to the deal if one is linked; shortlisting properties
            // needs a deal to live on, so the open one is found-or-created.
            $project = match (true) {
                ! empty($data['client_project_id']) => $this->resolveExplicitProject((int) $data['client_project_id']),
                ! empty($data['properties']) => $this->ensureActiveClientProject->handle($client),
                default => $this->reopenDesireProject($client),
            };

            $call = Call::create([
                'client_id' => $client->id,
                'client_project_id' => $project?->id,
                'agent_id' => $data['agent_id'] ?? $actor->id,
                'direction' => $data['direction'],
                'outcome_id' => $data['outcome_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'topics' => $data['topics'] ?? null,
                'called_at' => $data['called_at'] ?? now(),
            ]);

            if (! empty($data['properties'])) {
                $this->addShortlistItems->handle($project, $data['properties'], $call->id);
            }

            if (! empty($data['desire'])) {
                $this->upsertDesire->handle($client, $data['desire']);
            }

            // The action belongs to the deal if one is linked, otherwise the client.
            // A call next action defaults to the client's sales agent (the request
            // only forces an assignee for in-site visits).
            if (! empty($data['next_action'])) {
                $nextAction = $this->createNextAction->handle(
                    $project ?? $client, $call, $data['next_action'], $client->assigned_agent_id ?? $actor->id,
                );

                // A visit-type next step IS the scheduling — materialize the visit(s).
                $this->syncVisitFromNextAction->handle($nextAction);
            } else {
                // No follow-up planned: the call still FULFILS the open plan —
                // close it, or it lingers pending forever (stuck CTA, reminders).
                $this->closePendingNextActions->handle($project ?? $client);
            }

            // Return the created instance (not a refetch) so the API responds 201.
            return $call;
        });
    }

    /**
     * A call may target an archived project ONLY when it sits on the desire list —
     * the reconnect call is what reopens it. A plain archive must be explicitly
     * reactivated first ("nothing is added to a closed project").
     */
    private function resolveExplicitProject(int $projectId): ClientProject
    {
        $project = ClientProject::findOrFail($projectId);

        if ($project->isArchived() && $project->closed_to_desire_at !== null) {
            return $this->reactivateClientProject->handle($project);
        }

        abort_unless($project->isActive(), 422, 'This project is closed — reactivate it before logging on it.');

        return $project;
    }

    /**
     * No explicit project and no properties: a plain call stays client-level —
     * UNLESS the client has no open project but one waiting on the desire list;
     * the new call is the reopen signal, so it reactivates and attaches to it.
     */
    private function reopenDesireProject(Client $client): ?ClientProject
    {
        $hasOpen = $client->projects()->active()->exists();

        if ($hasOpen) {
            return null;
        }

        $waiting = $client->projects()->archived()
            ->whereNotNull('closed_to_desire_at')
            ->latest('id')
            ->first();

        return $waiting !== null ? $this->reactivateClientProject->handle($waiting) : null;
    }
}
