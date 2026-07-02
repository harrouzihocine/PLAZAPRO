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
        // type_id / floor_id / wilaya_id / sale_status accept either a single value
        // or a list (multi-select filters) — cast to an array and use whereIn.
        $asList = fn (string $key) => array_values(array_filter(
            (array) $request->query($key),
            fn ($v) => $v !== '' && $v !== null,
        ));

        $units = Unit::query()
            ->with(['type', 'floor', 'location.wilaya', 'location.commune'])
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->query('location_id')))
            ->when($asList('type_id'), fn ($q, $ids) => $q->whereIn('type_id', $ids))
            ->when($asList('floor_id'), fn ($q, $ids) => $q->whereIn('floor_id', $ids))
            // Geographic location lives on the unit's project (location), not the unit.
            ->when($asList('wilaya_id'), fn ($q, $ids) => $q->whereHas('location', fn ($l) => $l->whereIn('wilaya_id', $ids)))
            ->when($asList('commune_id'), fn ($q, $ids) => $q->whereHas('location', fn ($l) => $l->whereIn('commune_id', $ids)))
            ->when($asList('sale_status'), fn ($q, $statuses) => $q->whereIn('sale_status', $statuses))
            ->when($asList('priority'), fn ($q, $priorities) => $q->whereIn('gtm_priority', $priorities))
            ->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', $request->query('min_price')))
            ->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', $request->query('max_price')))
            ->when($request->filled('min_area'), fn ($q) => $q->where('area_sqm', '>=', $request->query('min_area')))
            ->when($request->filled('max_area'), fn ($q) => $q->where('area_sqm', '<=', $request->query('max_area')))
            // Highest GTM priority first so the vente team sees what to push on top.
            ->orderByRaw("FIELD(gtm_priority, 'critical', 'high', 'medium', 'low')")
            ->orderBy('reference')
            ->get();

        return UnitResource::collection($units);
    }

    public function show(Unit $unit): UnitResource
    {
        return new UnitResource($unit->load(['type', 'floor', 'location.wilaya', 'location.commune']));
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
