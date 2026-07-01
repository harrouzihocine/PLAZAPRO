<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The role-aware dashboard. Read-only KPIs and short lists scoped to the caller
 * by BuildDashboard (agent → own book, manager/admin → everything). Thin: the
 * scoping and every figure live in the query service.
 */
class DashboardController extends Controller
{
    public function show(Request $request, BuildDashboard $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($request->user())]);
    }
}
