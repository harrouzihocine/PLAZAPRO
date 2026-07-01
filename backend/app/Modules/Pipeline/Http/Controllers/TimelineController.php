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
use Illuminate\Routing\Controller;

/**
 * The interaction timeline shown on the client file: the client's calls and
 * visits, plus the open pending next action(s) for the client and its deals.
 */
class TimelineController extends Controller
{
    public function show(Client $client): JsonResponse
    {
        $calls = Call::query()
            ->where('client_id', $client->id)->active()
            ->with(['agent', 'outcome'])->latest('called_at')->get();

        $visits = Visit::query()
            ->where('client_id', $client->id)->active()
            ->with(['agent', 'unit', 'outcome'])->orderByDesc('scheduled_at')->get();

        $projectIds = ClientProject::query()->where('client_id', $client->id)->pluck('id');

        $nextActions = NextAction::query()
            ->pending()
            ->with('assignedTo')
            ->where(function ($q) use ($client, $projectIds) {
                $q->where(fn ($s) => $s->where('subject_type', 'client')->where('subject_id', $client->id))
                    ->orWhere(fn ($s) => $s->where('subject_type', 'client_project')->whereIn('subject_id', $projectIds));
            })
            ->orderBy('due_at')
            ->get();

        return response()->json([
            'data' => [
                'calls' => CallResource::collection($calls)->resolve(),
                'visits' => VisitResource::collection($visits)->resolve(),
                'next_actions' => NextActionResource::collection($nextActions)->resolve(),
            ],
        ]);
    }
}
