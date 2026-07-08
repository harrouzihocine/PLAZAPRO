<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientDetailGrant;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Actions\EnsureProjectConversation;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reactivate an archived project AND hand it to a chosen team, mirroring the
 * duplicate-client resolver (ResolveDuplicateRequest):
 *
 *  - No team chosen, or the chosen team is exactly today's contributors → a plain
 *    reactivate (unchanged behaviour).
 *  - IN_PLACE — reactivate this same project and REPLACE its contributor list with
 *    the chosen team: viewers not chosen are hidden, chosen ones are added. The
 *    original creator can never be hidden (they always keep access to what they
 *    opened). One continued workspace.
 *  - SEPARATE — the original stays ARCHIVED; a brand-new project is opened for the
 *    team on the same client, continuing this one but siloed from it
 *    (hidden_from_owner), exactly like the duplicate fork.
 *
 * Either hand-off grants each chosen user the client's details (they must be able
 * to call the client) and notifies them.
 */
class ReactivateProjectWithHandoff
{
    public function __construct(
        private ReactivateClientProject $reactivate,
        private CreateClientProject $createProject,
        private EnsureProjectConversation $ensureConversation,
    ) {}

    public function handle(ClientProject $project, User $resolver, array $data = []): ClientProject
    {
        $handlerIds = collect($data['handler_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        // No hand-off, or the chosen team equals today's contributors: plain reactivate.
        $current = $project->contributorIds()->sort()->values();
        if ($handlerIds->isEmpty() || $handlerIds->sort()->values()->all() === $current->all()) {
            return $this->reactivate->handle($project);
        }

        $mode = $data['mode'] ?? null;
        abort_unless(in_array($mode, ['in_place', 'separate'], true), 422, 'Choose how to hand the project over.');

        // Everyone NEWLY handed the project must be allowed to hold one. The
        // original creator is grandfathered — they already own it and are always
        // in the set (the UI locks them in), so they are not re-checked.
        $assignable = $handlerIds->reject(fn (int $id) => $id === (int) $project->created_by)->values();
        $handlers = User::query()->whereIn('id', $assignable->all())->with('role.permissions')->get();
        abort_if(
            $handlers->count() !== $assignable->count()
                || $handlers->contains(fn (User $u) => ! $u->can('projects.create')),
            422,
            'Every selected user must be allowed to open a client project.',
        );

        return DB::transaction(fn () => $mode === 'separate'
            ? $this->separate($project, $resolver, $handlerIds, (int) ($data['primary_id'] ?? 0))
            : $this->inPlace($project, $resolver, $handlerIds));
    }

    /** Reactivate this project and replace its contributors with the chosen team. */
    private function inPlace(ClientProject $project, User $resolver, Collection $handlerIds): ClientProject
    {
        $this->reactivate->handle($project);

        $creatorId = (int) $project->created_by;
        $chosenViewers = $handlerIds->reject(fn (int $id) => $id === $creatorId);

        // Hide current viewers who are not on the new team (the creator can't be hidden).
        $project->viewers()->wherePivotNull('hidden_at')->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $creatorId || $chosenViewers->contains($id))
            ->each(fn (int $id) => $project->viewers()->updateExistingPivot($id, ['hidden_at' => now()]));

        // Add / un-hide the chosen team; grant each the client's details.
        $newlyAdded = collect();
        foreach ($chosenViewers as $id) {
            $wasActive = $project->viewers()->wherePivotNull('hidden_at')->whereKey($id)->exists();
            $project->viewers()->syncWithoutDetaching([$id => ['added_by' => $resolver->id, 'hidden_at' => null]]);
            $project->viewers()->updateExistingPivot($id, ['hidden_at' => null]);
            $this->grantDetails($project->client_id, $id, $resolver->id);
            if (! $wasActive) {
                $newlyAdded->push($id);
            }
        }

        // Chat membership follows the contributor list.
        $this->ensureConversation->handle($project);

        $this->notify(
            $newlyAdded,
            'project_handed',
            "/clients/{$project->client_id}/projects/{$project->id}",
        );

        return $project->fresh();
    }

    /** Leave the original archived; open a new siloed project for the team. */
    private function separate(ClientProject $project, User $resolver, Collection $handlerIds, int $primaryId): ClientProject
    {
        abort_unless($handlerIds->contains($primaryId), 422, 'The primary owner must be one of the selected users.');

        $client = $project->client;

        // A brand-new project on the same client, opened on behalf of the primary
        // handler, continuing this one but siloed from it (hidden_from_owner): the
        // client's own agent won't see it. Nothing is copied — the brief lives on
        // the client and is visible through it.
        $newProject = $this->createProject->handle($client, [], $primaryId);
        $newProject->update([
            'continued_from_project_id' => $project->id,
            'hidden_from_owner' => true,
        ]);

        foreach ($handlerIds->reject(fn (int $id) => $id === $primaryId) as $id) {
            $newProject->viewers()->syncWithoutDetaching([$id => ['added_by' => $resolver->id, 'hidden_at' => null]]);
            $newProject->viewers()->updateExistingPivot($id, ['hidden_at' => null]);
        }
        $this->ensureConversation->handle($newProject);

        foreach ($handlerIds as $id) {
            $this->grantDetails($client->id, $id, $resolver->id);
        }

        $this->notify(
            $handlerIds,
            'project_set_up',
            "/clients/{$client->id}/projects/{$newProject->id}",
        );

        return $newProject->fresh();
    }

    private function grantDetails(int $clientId, int $userId, int $resolverId): void
    {
        ClientDetailGrant::query()->firstOrCreate(
            ['client_id' => $clientId, 'user_id' => $userId],
            ['granted_by' => $resolverId],
        );
    }

    private function notify(Collection $userIds, string $key, string $link): void
    {
        if ($userIds->isEmpty()) {
            return;
        }

        User::query()->whereIn('id', $userIds->all())->get()->each(
            fn (User $u) => $u->notify(new DomainNotification(kind: 'project', key: $key, link: $link)),
        );
    }
}
