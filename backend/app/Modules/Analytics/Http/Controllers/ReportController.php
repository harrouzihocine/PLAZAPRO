<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildSourceRoiReport;
use App\Modules\Analytics\Actions\BuildUnitIntelligence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Manager/admin analytics reports. Read-only aggregates over existing tables,
 * gated behind dashboard.view + reports.view (see routes.php). Thin: the queries
 * live in the Build* services.
 */
class ReportController extends Controller
{
    public function sourceRoi(Request $request, BuildSourceRoiReport $action): JsonResponse
    {
        return response()->json([
            'data' => $action->handle(
                $request->filled('from') ? $request->string('from')->toString() : null,
                $request->filled('to') ? $request->string('to')->toString() : null,
            ),
        ]);
    }

    public function units(Request $request, BuildUnitIntelligence $action): JsonResponse
    {
        return response()->json([
            'data' => $action->handle(
                $request->filled('location_id') ? $request->integer('location_id') : null,
            ),
        ]);
    }
}
