<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\BuildStackingPlan;
use App\Modules\Inventory\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The visual stacking plan for a location — a read view over its units grouped
 * by block/floor/position and colour-coded by sale_status. Requires units.view.
 */
class StackingController extends Controller
{
    public function show(Location $location, BuildStackingPlan $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($location)]);
    }
}
