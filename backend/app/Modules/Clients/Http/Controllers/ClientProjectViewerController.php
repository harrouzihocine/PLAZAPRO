<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Actions\EnsureProjectConversation;
use App\Modules\Settings\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Per-project visibility list — "who can see this project". The creator always
 * sees it; other users are added by anyone holding projects.contributors.
 * Viewers are hidden (kept + timestamped), never removed; re-adding un-hides.
 */
class ClientProjectViewerController extends Controller
{
    public function index(ClientProject $project): JsonResponse
    {
        // A project outside the user's visibility scope reads as absent.
        abort_unless($project->isVisibleTo(request()->user()), 404);

        return $this->list($project);
    }

    /** The list itself, unguarded — also the response to a successful add/hide. */
    private function list(ClientProject $project): JsonResponse
    {
        $project->load(['creator', 'viewers' => fn ($q) => $q->orderBy('name')]);

        $rows = collect();

        if ($project->creator !== null) {
            $rows->push([
                'id' => $project->creator->id,
                'name' => $project->creator->name,
                'is_creator' => true,
                'hidden' => false,
            ]);
        }

        foreach ($project->viewers as $viewer) {
            if ($viewer->id === $project->created_by) {
                continue; // the creator row above already covers them
            }
            $rows->push([
                'id' => $viewer->id,
                'name' => $viewer->name,
                'is_creator' => false,
                'hidden' => $viewer->pivot->hidden_at !== null,
            ]);
        }

        return response()->json(['data' => $rows->values()]);
    }

    /** Add a user to the list (or un-hide a previously hidden one). */
    public function store(Request $request, ClientProject $project, EnsureProjectConversation $syncChat): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::query()->findOrFail($validated['user_id']);

        abort_if($user->id === $project->created_by, 422, 'The creator always sees the project.');

        $project->viewers()->syncWithoutDetaching([
            $user->id => ['added_by' => $request->user()->id, 'hidden_at' => null],
        ]);
        // syncWithoutDetaching leaves an existing (hidden) row untouched — un-hide it.
        $project->viewers()->updateExistingPivot($user->id, ['hidden_at' => null]);

        // A contributor joins the project's chat (and can read its history).
        $syncChat->handle($project);

        return $this->list($project);
    }

    /** Hide a viewer (no remove — the grant history is kept). */
    public function hide(ClientProject $project, User $user, EnsureProjectConversation $syncChat): JsonResponse
    {
        abort_if($user->id === $project->created_by, 422, 'The creator cannot be hidden from their own project.');

        abort_unless(
            $project->viewers()->whereKey($user->id)->exists(),
            404,
            'This user is not on the project’s visibility list.',
        );

        $project->viewers()->updateExistingPivot($user->id, ['hidden_at' => now()]);

        // Leaving the visibility list also leaves the project's chat.
        $syncChat->handle($project);

        return $this->list($project);
    }
}
