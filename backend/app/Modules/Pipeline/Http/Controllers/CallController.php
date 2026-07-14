<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Actions\ApplyLogCorrection;
use App\Modules\Pipeline\Actions\CorrectCall;
use App\Modules\Pipeline\Actions\LogCall;
use App\Modules\Pipeline\Http\Requests\CorrectCallRequest;
use App\Modules\Pipeline\Http\Requests\LogCallRequest;
use App\Modules\Pipeline\Http\Resources\CallResource;
use App\Modules\Pipeline\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Calls on a client. Reading needs clients.view; logging a call needs calls.log
 * and always leaves a next action (LogCall + LogCallRequest).
 */
class CallController extends Controller
{
    public function index(Request $request, Client $client): AnonymousResourceCollection
    {
        $user = $request->user();

        // A client outside the caller's scope reads as absent (mirrors the client
        // show / timeline endpoints).
        abort_unless(
            Client::query()->visibleTo($user)->whereKey($client->id)->exists(),
            404,
        );

        // Only calls on a project the caller can see, plus client-level (no-project)
        // qualifying calls — so a duplicate-resolution "separate project" stays
        // siloed: the other agent's calls on the same client never surface here.
        $projectIds = ClientProject::query()
            ->where('client_id', $client->id)
            ->visibleTo($user)
            ->pluck('id');

        $calls = Call::query()
            ->where('client_id', $client->id)
            ->where(fn ($q) => $q->whereIn('client_project_id', $projectIds)->orWhereNull('client_project_id'))
            ->active()
            ->with(['agent', 'outcome'])
            ->latest('called_at')
            ->get();

        return CallResource::collection($calls);
    }

    public function store(LogCallRequest $request, Client $client, LogCall $action): CallResource
    {
        $call = $action->handle($client, $request->validated(), $request->user())
            ->load(['agent', 'outcome']);

        return new CallResource($call);
    }

    /**
     * Correct a call: cancels the original and returns the new version, keeping
     * both in history with the reason (CorrectCall → supersedeWith). If the call
     * had opened a still-waiting deal it is cancelled with it (needs
     * logs.cancel_deal); a deal with money/a sale on it blocks the edit. The
     * open plan the call created follows the new version. (ApplyLogCorrection.)
     */
    public function correct(
        CorrectCallRequest $request,
        Call $call,
        CorrectCall $action,
        ApplyLogCorrection $apply,
    ): CallResource {
        $reason = $request->validated('reason');

        $new = $apply->handle(
            $request->user(),
            $call->deal()->first(),
            'call',
            $call->id,
            "Log edited — {$reason}",
            fn () => $action->handle($call, $request->safe()->except('reason'), $reason),
        );

        return new CallResource($new->load(['agent', 'outcome']));
    }
}
