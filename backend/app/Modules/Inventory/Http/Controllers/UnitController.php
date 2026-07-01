<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\CancelUnit;
use App\Modules\Inventory\Actions\CorrectUnit;
use App\Modules\Inventory\Actions\CreateUnit;
use App\Modules\Inventory\Actions\UpdateUnit;
use App\Modules\Inventory\Http\Requests\CorrectUnitRequest;
use App\Modules\Inventory\Http\Requests\StoreUnitRequest;
use App\Modules\Inventory\Http\Requests\UpdateUnitRequest;
use App\Modules\Inventory\Http\Resources\UnitResource;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Units. Reads require units.view; writes require units.manage. price and
 * sale_status corrections are done via the dedicated /correct endpoint
 * (HasVersions); reference/spec edits via update.
 */
class UnitController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $units = Unit::query()
            ->with(['type', 'floor'])
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->query('location_id')))
            ->when($request->filled('type_id'), fn ($q) => $q->where('type_id', $request->query('type_id')))
            ->when($request->filled('floor_id'), fn ($q) => $q->where('floor_id', $request->query('floor_id')))
            ->when($request->filled('sale_status'), fn ($q) => $q->where('sale_status', $request->query('sale_status')))
            ->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', $request->query('min_price')))
            ->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', $request->query('max_price')))
            ->orderBy('reference')
            ->get();

        return UnitResource::collection($units);
    }

    public function show(Unit $unit): UnitResource
    {
        return new UnitResource($unit->load(['type', 'floor', 'location']));
    }

    public function store(StoreUnitRequest $request, Location $location, CreateUnit $action): UnitResource
    {
        return new UnitResource($action->handle($location, $request->validated())->load(['type', 'floor']));
    }

    public function update(UpdateUnitRequest $request, Unit $unit, UpdateUnit $action): UnitResource
    {
        return new UnitResource($action->handle($unit, $request->validated())->load(['type', 'floor']));
    }

    public function correct(CorrectUnitRequest $request, Unit $unit, CorrectUnit $action): JsonResponse
    {
        $corrected = $action->handle($unit, $request->validated())->load(['type', 'floor']);

        // The superseded replacement is a freshly-inserted row; a JsonResource
        // would otherwise auto-send 201. A correction is a 200 from the client's view.
        return (new UnitResource($corrected))->response()->setStatusCode(200);
    }

    public function destroy(Request $request, Unit $unit, CancelUnit $action): UnitResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new UnitResource($action->handle($unit, $reason));
    }
}
