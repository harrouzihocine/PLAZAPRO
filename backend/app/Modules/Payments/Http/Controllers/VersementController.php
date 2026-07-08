<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Actions\CorrectVersement;
use App\Modules\Payments\Actions\GenerateVersementDocument;
use App\Modules\Payments\Actions\RecordVersement;
use App\Modules\Payments\Actions\RefundVersement;
use App\Modules\Payments\Actions\UpdateReservedWindow;
use App\Modules\Payments\Http\Requests\CorrectVersementRequest;
use App\Modules\Payments\Http\Requests\GenerateDocumentRequest;
use App\Modules\Payments\Http\Requests\RecordVersementRequest;
use App\Modules\Payments\Http\Requests\RefundVersementRequest;
use App\Modules\Payments\Http\Requests\UpdateReservedWindowRequest;
use App\Modules\Payments\Http\Resources\DocumentResource;
use App\Modules\Payments\Http\Resources\VersementResource;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Recorded instalment payments on a deal. Reading needs versements.view; recording
 * needs versements.record; corrections (cancel-and-duplicate) need versements.cancel.
 * The running balance is computed server-side — never trusted from the client.
 */
class VersementController extends Controller
{
    public function index(Request $request, ClientProject $project): AnonymousResourceCollection
    {
        // ?unit_id scopes the read to ONE won apartment — its own payments and
        // its own balance against its own agreed price.
        $unitId = $request->filled('unit_id') ? (int) $request->query('unit_id') : null;

        $versements = $project->versements()
            ->active()
            ->when($unitId !== null, fn ($q) => $q->where('unit_id', $unitId))
            ->with(['method', 'recorder', 'refunder'])
            ->latest('paid_on')
            ->latest('id')
            ->get();

        // Refunded money went back to the client — it stays listed as history
        // but no longer counts toward the balance.
        $totalPaid = Money::sum($versements->reject->isRefunded()->pluck('amount'));
        $totalPrice = $project->agreedPriceForUnit($unitId);

        return VersementResource::collection($versements)->additional([
            'meta' => [
                'total_price' => $totalPrice,
                'total_paid' => $totalPaid,
                'balance' => $totalPrice !== null ? Money::sub($totalPrice, $totalPaid) : null,
            ],
        ]);
    }

    public function store(RecordVersementRequest $request, ClientProject $project, RecordVersement $action): VersementResource
    {
        $versement = $action->handle($project, $request->validated(), $request->user())
            ->load(['method', 'recorder']);

        return new VersementResource($versement);
    }

    /**
     * Move the back-to-market deadline of a unit this project holds — the
     * hold-edit half of the deposit modal, no payment recorded.
     */
    public function updateReservedWindow(
        UpdateReservedWindowRequest $request,
        ClientProject $project,
        Unit $unit,
        UpdateReservedWindow $action,
    ): JsonResponse {
        $unit = $action->handle($project, $unit, Carbon::parse($request->validated('reserved_until')));

        return response()->json(['data' => [
            'reserved_expires_at' => $unit->reserved_expires_at?->toIso8601String(),
        ]]);
    }

    /**
     * Correct a recorded versement via cancel-and-duplicate. Returns the corrected
     * (newly created) row, so the response status is 201.
     */
    public function correct(CorrectVersementRequest $request, Versement $versement, CorrectVersement $action): VersementResource
    {
        $replacement = $action->handle($versement, $request->validated())
            ->load(['method', 'recorder']);

        return new VersementResource($replacement);
    }

    /**
     * Refund a done versement — the money went back to the client. The row is
     * kept in history flagged refunded; its allocation is reversed.
     */
    public function refund(RefundVersementRequest $request, Versement $versement, RefundVersement $action): VersementResource
    {
        $refunded = $action->handle($versement, $request->validated('reason'), $request->user())
            ->load(['method', 'recorder', 'refunder']);

        return new VersementResource($refunded);
    }

    /** Generate a branded receipt for the versement (rendered on the queue worker). */
    public function generateDocument(GenerateDocumentRequest $request, Versement $versement, GenerateVersementDocument $action): DocumentResource
    {
        $document = $action->handle($versement, $request->user());

        return new DocumentResource($document);
    }
}
