<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Http\Resources\CallResource;
use App\Modules\Pipeline\Http\Resources\NextActionResource;
use App\Modules\Pipeline\Http\Resources\VisitResource;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The interaction timeline shown on the client file: the client's calls and
 * visits, plus the open pending next action(s) for the client and its deals.
 *
 * ?project_id scopes it to ONE project's story: the logs linked to that project
 * plus the client-level ones (the qualifying calls that precede any project).
 */
class TimelineController extends Controller
{
    public function show(Request $request, Client $client): JsonResponse
    {
        // Same visibility rule as ClientController::show — a client outside the
        // caller's scope reads as absent (the timeline is the client's whole
        // interaction history, including corrected versions).
        abort_unless(
            Client::query()->visibleTo($request->user())->whereKey($client->id)->exists(),
            404,
        );

        $projectId = $request->query('project_id');

        // Logs are visible per project: only those on a project the caller can see
        // (ClientProject::visibleTo — projects.view_all sees all), plus client-level
        // (no-project) qualifying logs. This silos a duplicate-resolution "separate
        // project": the other agent's activity on the same client never surfaces
        // here. ?project_id narrows the same visible set to one project's story.
        $projectIds = ClientProject::query()
            ->where('client_id', $client->id)
            ->visibleTo($request->user())
            ->when($projectId, fn ($q) => $q->whereKey($projectId))
            ->pluck('id');

        $scoped = fn ($q) => $q->where(fn ($s) => $s
            ->whereIn('client_project_id', $projectIds)
            ->orWhereNull('client_project_id'));

        // Cancelled/superseded versions are returned too — an edit never hides
        // its history (the FE nests old versions under their replacement).
        $calls = Call::query()
            ->where('client_id', $client->id)
            ->where($scoped)
            ->with(['agent', 'outcome', 'supersedes', 'deal'])->latest('called_at')->get();

        $visits = Visit::query()
            ->where('client_id', $client->id)
            ->where($scoped)
            ->with(['agent', 'unit.floor', 'unit.location', 'unit.location.type', 'outcome', 'supersedes', 'deal'])->orderByDesc('scheduled_at')->get();

        // Only active pending actions — a superseded (cancelled) row keeps its
        // 'pending' state value but must not surface as the open action.
        $nextActions = NextAction::query()
            ->active()
            ->pending()
            ->with('assignedTo')
            ->where(function ($q) use ($client, $projectIds) {
                $q->where(fn ($s) => $s->where('subject_type', 'client')->where('subject_id', $client->id))
                    ->orWhere(fn ($s) => $s->where('subject_type', 'client_project')->whereIn('subject_id', $projectIds));
            })
            ->orderBy('due_at')
            ->get();

        // The full plan history (completed / cancelled / superseded next actions)
        // for the "Next actions" timeline tab — the open one above stays the CTA.
        $actionHistory = NextAction::query()
            ->with(['assignedTo', 'supersedes'])
            ->where(function ($q) use ($client, $projectIds) {
                $q->where(fn ($s) => $s->where('subject_type', 'client')->where('subject_id', $client->id))
                    ->orWhere(fn ($s) => $s->where('subject_type', 'client_project')->whereIn('subject_id', $projectIds));
            })
            ->orderByDesc('due_at')
            ->get();

        return response()->json([
            'data' => [
                'calls' => CallResource::collection($calls)->resolve(),
                'visits' => VisitResource::collection($visits)->resolve(),
                'next_actions' => NextActionResource::collection($nextActions)->resolve(),
                'next_action_history' => NextActionResource::collection($actionHistory)->resolve(),
            ],
        ]);
    }
}
