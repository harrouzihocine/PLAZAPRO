<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientDetailGrant;
use App\Modules\Clients\Models\ClientDuplicateRequest;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolve a duplicate request, three ways:
 *
 *  - DENY — the finder gets nothing; no client is taken.
 *  - SHARE_PROJECT — the finder JOINS one existing project of the client as a
 *    contributor (a merge). Reveal-details additionally unlocks the client's
 *    identity for that finder (a detail grant).
 *  - FORK_PROJECT — the finder gets their OWN new project on the same client, a
 *    continuation of the chosen one but siloed from it (hidden_from_owner): the
 *    two agents work the client independently. The finder is granted the client's
 *    details (they must be able to call the client) but never sees the original
 *    project nor who is behind it.
 *
 * The finder is notified of the outcome either way.
 */
class ResolveDuplicateRequest
{
    public function __construct(private CreateClientProject $createClientProject) {}

    public function handle(ClientDuplicateRequest $request, string $action, User $resolver, array $data = []): ClientDuplicateRequest
    {
        abort_unless($request->status === 'pending', 422, 'This request has already been resolved.');

        return DB::transaction(function () use ($request, $action, $resolver, $data) {
            match ($action) {
                'share_project' => $this->share($request, $resolver, $data),
                'fork_project' => $this->fork($request, $resolver, $data),
                default => $this->deny($request, $resolver),
            };

            return $request->fresh(['existingClient', 'requester', 'sharedProject', 'spawnedProject']);
        });
    }

    private function deny(ClientDuplicateRequest $request, User $resolver): void
    {
        $request->update([
            'status' => 'denied',
            'resolution' => 'deny',
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
        ]);

        $this->notifyFinder(
            $request,
            'Duplicate request declined',
            'Your request to add an existing client was declined.',
            '/clients',
        );
    }

    private function share(ClientDuplicateRequest $request, User $resolver, array $data): void
    {
        $project = ClientProject::query()->findOrFail($data['project_id']);
        abort_unless(
            (int) $project->client_id === (int) $request->existing_client_id,
            422,
            'The chosen project does not belong to this client.',
        );

        $finderId = $request->requested_by;
        $shareDetails = (bool) ($data['share_details'] ?? false);

        // Add the finder as a project contributor (un-hiding any prior hidden row).
        $project->viewers()->syncWithoutDetaching([
            $finderId => ['added_by' => $resolver->id, 'hidden_at' => null],
        ]);
        $project->viewers()->updateExistingPivot($finderId, ['hidden_at' => null]);

        if ($shareDetails) {
            ClientDetailGrant::query()->firstOrCreate(
                ['client_id' => $request->existing_client_id, 'user_id' => $finderId],
                ['granted_by' => $resolver->id],
            );
        }

        $request->update([
            'status' => 'shared',
            'resolution' => 'share_project',
            'shared_project_id' => $project->id,
            'share_details' => $shareDetails,
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
        ]);

        $this->notifyFinder(
            $request,
            'A project was shared with you',
            'A supervisor shared an existing client’s project with you.',
            '/clients/'.$request->existing_client_id.'/projects/'.$project->id,
        );
    }

    private function fork(ClientDuplicateRequest $request, User $resolver, array $data): void
    {
        $source = ClientProject::query()->findOrFail($data['project_id']);
        abort_unless(
            (int) $source->client_id === (int) $request->existing_client_id,
            422,
            'The chosen project does not belong to this client.',
        );

        $finderId = $request->requested_by;

        // A brand-new project on the SAME client, opened on behalf of the finder,
        // continuing the chosen one but siloed from it (hidden_from_owner): the
        // client's own agent won't see it, and the finder — not the client owner —
        // never sees the original. The client-level desire (the brief) is already
        // visible to the finder through the client, so nothing is copied over.
        $project = $this->createClientProject->handle($request->existingClient, [], $finderId);
        $project->update([
            'continued_from_project_id' => $source->id,
            'hidden_from_owner' => true,
        ]);

        // The finder must be able to actually call the client — unlock its details
        // (their identity/contact, NOT the client's ownership, which stays behind
        // clients.manage, so the original's agent is not revealed).
        ClientDetailGrant::query()->firstOrCreate(
            ['client_id' => $request->existing_client_id, 'user_id' => $finderId],
            ['granted_by' => $resolver->id],
        );

        $request->update([
            'status' => 'forked',
            'resolution' => 'fork_project',
            'spawned_project_id' => $project->id,
            'share_details' => true,
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
        ]);

        $this->notifyFinder(
            $request,
            'A client was set up for you',
            'A supervisor set this client up as your own project — open it to start.',
            '/clients/'.$request->existing_client_id.'/projects/'.$project->id,
        );
    }

    private function notifyFinder(ClientDuplicateRequest $request, string $title, string $body, string $link): void
    {
        $finder = User::query()->find($request->requested_by);
        $finder?->notify(new DomainNotification(kind: 'duplicate', title: $title, body: $body, link: $link));
    }
}
