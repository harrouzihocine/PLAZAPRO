<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\BuildDesireMatches;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The dedicated "Desire matches" board — waiting clients on the desire list whose
 * criteria now fit available inventory (the reconnect signal that complements the
 * unit-match notifications). Read-only and company-wide: it is an oversight monitor
 * (gated by oversight.matches in the route), so it lists every waiting client's
 * matches regardless of who owns the lead — a manager triages and delegates here.
 */
class DesireMatchController extends Controller
{
    public function index(BuildDesireMatches $action): JsonResponse
    {
        return response()->json(['data' => $action->handle(null)]);
    }
}
