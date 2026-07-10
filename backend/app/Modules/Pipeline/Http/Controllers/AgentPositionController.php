<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Pipeline\Actions\RecordAgentPosition;
use App\Modules\Pipeline\Http\Requests\RecordPositionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The device→server half of live tracking: an on-duty agent's phone posts a
 * fix every so often (the client throttles; the route rate-limits as a
 * backstop). Everything else — geofences, ETA, the live-map broadcast —
 * happens inside RecordAgentPosition.
 */
class AgentPositionController extends Controller
{
    public function store(RecordPositionRequest $request, RecordAgentPosition $action): JsonResponse
    {
        $position = $action->handle($request->user(), $request->validated());

        return response()->json(['data' => [
            'recorded_at' => $position->recorded_at,
        ]], 201);
    }
}
