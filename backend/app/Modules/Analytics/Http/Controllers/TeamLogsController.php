<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildTeamLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The Team Logs feed. Open to any authed user but self-scoped unless the caller
 * has logs.view_all (then it widens company-wide with a user selector). Thin: the
 * scoping and every figure live in BuildTeamLogs. Read-only.
 */
class TeamLogsController extends Controller
{
    public function index(Request $request, BuildTeamLogs $action): JsonResponse
    {
        $user = $request->user();
        $filters = $request->only(['user_id', 'type', 'from', 'to', 'mode', 'page']);

        // Self-scope by default: only logs.view_all may look across users. Everyone
        // else is pinned to their own logs — any user_id in the request is ignored.
        if (! $user->can('logs.view_all')) {
            $filters['user_id'] = $user->id;
        }

        // Client phone rides each row only for clients.view_details holders — it
        // powers the call/WhatsApp affordance in the feed (fail-closed otherwise).
        $filters['can_view_details'] = (bool) $user->can('clients.view_details');

        return response()->json($action->handle($filters));
    }
}
