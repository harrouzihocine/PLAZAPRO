<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Collaboration\Models\Conversation;
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
            $conversation = Conversation::query()
                ->where('type', ConversationType::Project->value)
                ->where('subject_type', $project->getMorphClass())
                ->where('subject_id', $project->id)
                ->first();

            if ($conversation === null) {
                $project->loadMissing('client');

                $conversation = Conversation::create([
                    'type' => ConversationType::Project->value,
                    'title' => trim(($project->client?->full_name ?? 'Client')." · Project #{$project->id}"),
                    'subject_type' => $project->getMorphClass(),
                    'subject_id' => $project->id,
                    'created_by' => $project->created_by,
                ]);
            }

            $this->syncParticipants($conversation, $project);

            return $conversation;
        });
    }

    /**
     * Participants mirror the contributors exactly: the creator (admin) plus the
     * non-hidden viewers. Newly added contributors join (and can read the whole
     * history); hidden ones leave. Existing rows keep their joined_at.
     */
    private function syncParticipants(Conversation $conversation, ClientProject $project): void
    {
        $contributorRoles = collect($project->created_by !== null ? [$project->created_by => 'admin'] : []);

        foreach ($project->viewers()->whereNull('client_project_viewers.hidden_at')->pluck('users.id') as $viewerId) {
            $contributorRoles[$viewerId] ??= 'member';
        }

        $current = $conversation->participants()->pluck('users.id');

        $conversation->participants()->detach($current->diff($contributorRoles->keys())->all());

        $now = now();
        $conversation->participants()->attach(
            $contributorRoles->keys()->diff($current)
                ->mapWithKeys(fn ($id) => [$id => ['role' => $contributorRoles[$id], 'joined_at' => $now]])
                ->all(),
        );
    }
}
