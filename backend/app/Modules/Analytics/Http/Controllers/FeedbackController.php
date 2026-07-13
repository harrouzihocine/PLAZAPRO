<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildLocationFeedback;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The "Voice of Client" feedback analytics for a development (Location) or a single
 * unit drill-down. Read-only aggregates over the logs, gated behind units.stats
 * (see routes.php). Thin: the queries live in BuildLocationFeedback.
 */
class FeedbackController extends Controller
{
    public function location(Request $request, Location $location, BuildLocationFeedback $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($location, $this->window($request))]);
    }

    public function unit(Request $request, Unit $unit, BuildLocationFeedback $action): JsonResponse
    {
        $unit->loadMissing('location');

        return response()->json([
            'data' => $action->handle($unit->location, $this->window($request), $unit),
        ]);
    }

    /**
     * @return array{from: ?string, to: ?string}
     */
    private function window(Request $request): array
    {
        return [
            'from' => $request->filled('from') ? $request->string('from')->toString() : null,
            'to' => $request->filled('to') ? $request->string('to')->toString() : null,
        ];
    }
}
