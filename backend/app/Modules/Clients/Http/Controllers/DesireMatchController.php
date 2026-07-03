<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\BuildDesireMatches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The dedicated "Desire matches" board — waiting clients on the desire list whose
 * criteria now fit available inventory (the reconnect signal that complements the
 * unit-match notifications). Read-only, agent-scoped.
 */
class DesireMatchController extends Controller
{
    public function index(Request $request, BuildDesireMatches $action): JsonResponse
    {
        $user = $request->user();
        $agentId = $user->isAgent() ? $user->id : null;

        return response()->json(['data' => $action->handle($agentId)]);
    }
}
