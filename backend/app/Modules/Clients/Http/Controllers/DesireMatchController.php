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
 * unit-match notifications). Read-only and company-wide: it is an oversight monitor
 * (gated by oversight.matches in the route), so it lists every waiting client's
 * matches regardless of who owns the lead — a manager triages and delegates here.
 *
 * Paginated ({items, meta}) with server-side search / unassigned filters: the
 * board can carry thousands of waiting clients, so the page lazy-loads instead of
 * shipping them all at once.
 */
class DesireMatchController extends Controller
{
    public function index(Request $request, BuildDesireMatches $action): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'unassigned' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json(['data' => $action->handle(null, $filters)]);
    }
}
