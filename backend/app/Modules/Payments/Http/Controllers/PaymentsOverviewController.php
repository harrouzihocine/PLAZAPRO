<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Actions\BuildPaymentsOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The cross-project payment follow-up hub (/payments): reserved units,
 * reservations, and the instalments to chase. Read-only; gated versements.view.
 */
class PaymentsOverviewController extends Controller
{
    public function index(Request $request, BuildPaymentsOverview $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($request->user())]);
    }
}
