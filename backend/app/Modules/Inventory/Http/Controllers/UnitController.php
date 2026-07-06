<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\BuildUnitInsights;
use App\Modules\Inventory\Actions\BuildUnitProjectLogs;
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
        // room_number_id / floor_id / wilaya_id / sale_status accept either a single
        // value or a list (multi-select filters) — cast to an array and use whereIn.
        $asList = fn (string $key) => array_values(array_filter(
            (array) $request->query($key),
            fn ($v) => $v !== '' && $v !== null,
        ));

        $units = Unit::query()
            ->with(['roomNumber', 'floor', 'location.wilaya', 'location.commune', 'location.type', 'location.contractType', 'activeReservations:id,unit_id,client_project_id'])
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('search'), fn ($q) => $q->where('reference', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->query('location_id')))
            ->when($asList('room_number_id'), fn ($q, $ids) => $q->whereIn('room_number_id', $ids))
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
            ->orderBy('reference');

        // The global browse always sends ?page → server-paginated (per_page
        // capped). Location-scoped reads (LocationDetailView / boxes) send no page
        // and need the whole bounded set (one building), so return the full list —
        // this keeps the lazy paginator correct even when the global browse is
        // itself filtered by location.
        return $request->filled('page')
            ? UnitResource::collection(
                $units->paginate(max(1, min((int) $request->query('per_page', 25), 100)))->withQueryString(),
            )
            : UnitResource::collection($units->get());
    }

    public function show(Unit $unit): UnitResource
    {
        return new UnitResource($unit->load(['roomNumber', 'floor', 'location.wilaya', 'location.commune', 'location.type', 'location.contractType']));
    }

    /** Read-only stats + payments summary for the unit detail page. */
    public function insights(Request $request, Unit $unit, BuildUnitInsights $action): JsonResponse
    {
        $canSeeMoney = (bool) $request->user()?->can('versements.view');

        return response()->json(['data' => $action->handle($unit, $canSeeMoney)]);
    }

    /**
     * The interaction logs (calls + visits) of every visible client project that
     * has touched this unit, grouped per project — for the unit page's log tab.
     */
    public function projectLogs(Request $request, Unit $unit, BuildUnitProjectLogs $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($unit, $request->user())]);
    }

    public function store(StoreUnitRequest $request, Location $location, CreateUnit $action): UnitResource
    {
        return new UnitResource($action->handle($location, $request->validated())->load(['roomNumber', 'floor']));
    }

    public function update(UpdateUnitRequest $request, Unit $unit, UpdateUnit $action): UnitResource
    {
        return new UnitResource($action->handle($unit, $request->validated())->load(['roomNumber', 'floor']));
    }

    public function correct(CorrectUnitRequest $request, Unit $unit, CorrectUnit $action): JsonResponse
    {
        $corrected = $action->handle($unit, $request->validated())->load(['roomNumber', 'floor']);

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
