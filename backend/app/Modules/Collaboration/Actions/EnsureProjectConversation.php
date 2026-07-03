<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every client project owns ONE dedicated chat — the project's communication
 * history between its contributors. Find-or-create it, then reconcile the
 * participant list with the project's contributors (creator + non-hidden
 * viewers): idempotent, so it runs on project creation, on every viewer
 * change, and from the backfill command.
 */
class EnsureProjectConversation
{
    public function handle(ClientProject $project): Conversation
    {
        return DB::transaction(function () use ($project) {
            // Serialize per project: two users opening the chat concurrently (or
            // a GET racing project creation) must not both see "no conversation"
            // and create two threads for one project.
            ClientProject::query()->whereKey($project->id)->lockForUpdate()->first();

            $conversation = $this->find($project);

            if ($conversation === null) {
                $project->loadMissing('client');

                $conversation = Conversation::create([
                    'type' => ConversationType::Project->value,
                    'title' => trim(($project->client?->full_name ?? 'Client')." · Project #{$project->id}"),
                    'subject_type' => $project->getMorphClass(),
                    'subject_id' => $project->id,
                    'created_by' => $this->ownerId($project),
                ]);
            }

            $this->syncParticipants($conversation, $project);

            return $conversation;
        });
    }

    private function find(ClientProject $project): ?Conversation
    {
        return Conversation::query()
            ->where('type', ConversationType::Project->value)
            ->where('subject_type', $project->getMorphClass())
            ->where('subject_id', $project->id)
            ->first();
    }

    /**
     * Who anchors the thread. Normally the project creator; legacy projects
     * (pre-created_by) fall back to the client's sales agent, then to the
     * oldest account (the seeded super-admin) — conversations.created_by is
     * NOT NULL.
     */
    private function ownerId(ClientProject $project): int
    {
        return $project->created_by
            ?? $project->client?->assigned_agent_id
            ?? User::query()->orderBy('id')->value('id');
    }

    /**
     * Participants mirror the contributors exactly (ClientProject::contributorIds
     * — the one membership rule): the owner is admin, the rest members. Newly
     * added contributors join (and can read the whole history); hidden ones
     * leave. Existing rows keep their joined_at; no-op syncs issue no writes.
     */
    private function syncParticipants(Conversation $conversation, ClientProject $project): void
    {
        $ownerId = $this->ownerId($project);
        $contributorIds = $project->contributorIds()->push($ownerId)->unique();

        $current = $conversation->participants()->pluck('users.id');

        $toDetach = $current->diff($contributorIds);
        if ($toDetach->isNotEmpty()) {
            $conversation->participants()->detach($toDetach->all());
        }

        $toAttach = $contributorIds->diff($current);
        if ($toAttach->isNotEmpty()) {
            $now = now();
            $conversation->participants()->attach(
                $toAttach->mapWithKeys(fn ($id) => [$id => [
                    'role' => $id === $ownerId ? 'admin' : 'member',
                    'joined_at' => $now,
                ]])->all(),
            );
        }
    }
}
