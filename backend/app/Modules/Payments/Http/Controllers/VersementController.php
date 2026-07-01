<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Actions\CorrectVersement;
use App\Modules\Payments\Actions\GenerateVersementDocument;
use App\Modules\Payments\Actions\RecordVersement;
use App\Modules\Payments\Http\Requests\CorrectVersementRequest;
use App\Modules\Payments\Http\Requests\GenerateDocumentRequest;
use App\Modules\Payments\Http\Requests\RecordVersementRequest;
use App\Modules\Payments\Http\Resources\DocumentResource;
use App\Modules\Payments\Http\Resources\VersementResource;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Recorded instalment payments on a deal. Reading needs versements.view; recording
 * needs versements.record; corrections (cancel-and-duplicate) need versements.cancel.
 * The running balance is computed server-side — never trusted from the client.
 */
class VersementController extends Controller
{
    public function index(ClientProject $project): AnonymousResourceCollection
    {
        $versements = $project->versements()
            ->active()
            ->with(['method', 'recorder'])
            ->latest('paid_on')
            ->latest('id')
            ->get();

        $totalPaid = Money::sum($versements->pluck('amount'));
        $totalPrice = $project->total_price !== null ? (string) $project->total_price : null;

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
     * Correct a recorded versement via cancel-and-duplicate. Returns the corrected
     * (newly created) row, so the response status is 201.
     */
    public function correct(CorrectVersementRequest $request, Versement $versement, CorrectVersement $action): VersementResource
    {
        $replacement = $action->handle($versement, $request->validated())
            ->load(['method', 'recorder']);

        return new VersementResource($replacement);
    }

    /** Generate a branded receipt for the versement (rendered on the queue worker). */
    public function generateDocument(GenerateDocumentRequest $request, Versement $versement, GenerateVersementDocument $action): DocumentResource
    {
        $document = $action->handle($versement, $request->user());

        return new DocumentResource($document);
    }
}
