<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Web\Services\BuildWebsiteStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The website-stats board (route-gated by web.stats): traffic, most-viewed
 * projects/units and contact clicks over a 7/30/90-day window.
 */
class WebStatsController extends Controller
{
    private const WINDOWS = [7, 30, 90];

    public function __invoke(Request $request, BuildWebsiteStats $builder): JsonResponse
    {
        $days = (int) $request->query('days', '30');

        abort_unless(in_array($days, self::WINDOWS, true), 422, 'days must be 7, 30 or 90.');

        return response()->json(['data' => $builder->build($days)]);
    }
}
