<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildTeamLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The company-wide Team Logs feed (gated by logs.view_all in the route). Thin:
 * the scoping and every figure live in BuildTeamLogs. Read-only.
 */
class TeamLogsController extends Controller
{
    public function index(Request $request, BuildTeamLogs $action): JsonResponse
    {
        return response()->json($action->handle(
            $request->only(['user_id', 'type', 'from', 'to', 'mode', 'page'])
        ));
    }
}
