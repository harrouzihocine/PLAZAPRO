<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\BuildStackingPlan;
use App\Modules\Inventory\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The visual stacking plan for a location — a read view over its units grouped
 * by block/floor/position and colour-coded by sale_status. Requires units.view;
 * the viewer decides whether sold cells still carry their price.
 */
class StackingController extends Controller
{
    public function show(Request $request, Location $location, BuildStackingPlan $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($location, $request->user())]);
    }
}
