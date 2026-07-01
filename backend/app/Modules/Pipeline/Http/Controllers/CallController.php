<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\Client;
use App\Modules\Pipeline\Actions\LogCall;
use App\Modules\Pipeline\Http\Requests\LogCallRequest;
use App\Modules\Pipeline\Http\Resources\CallResource;
use App\Modules\Pipeline\Models\Call;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Calls on a client. Reading needs clients.view; logging a call needs calls.log
 * and always leaves a next action (LogCall + LogCallRequest).
 */
class CallController extends Controller
{
    public function index(Client $client): AnonymousResourceCollection
    {
        $calls = Call::query()
            ->where('client_id', $client->id)
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
}
