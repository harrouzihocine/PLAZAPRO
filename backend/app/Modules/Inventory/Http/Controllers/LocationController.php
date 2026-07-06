<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\ArchiveLocation;
use App\Modules\Inventory\Actions\BuildLocationInsights;
use App\Modules\Inventory\Actions\CancelLocation;
use App\Modules\Inventory\Actions\CreateLocation;
use App\Modules\Inventory\Actions\ReactivateLocation;
use App\Modules\Inventory\Actions\UpdateLocation;
use App\Modules\Inventory\Http\Requests\StoreLocationRequest;
use App\Modules\Inventory\Http\Requests\UpdateLocationRequest;
use App\Modules\Inventory\Http\Resources\LocationResource;
use App\Modules\Inventory\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Locations (projects). Reads require units.view; writes require locations.manage.
 */
class LocationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Default lists live projects; ?status=archived feeds the "reactivate"
        // view; ?status=all returns every lifecycle state (removed included).
        $status = $request->query('status');

        $locations = Location::query()
            ->with(['wilaya', 'commune', 'type', 'contractType', 'paymentMethods'])
            ->when($status === 'archived', fn ($q) => $q->archived())
            ->when(! in_array($status, ['archived', 'all'], true), fn ($q) => $q->active())
            ->when($request->filled('wilaya_id'), fn ($q) => $q->where('wilaya_id', $request->query('wilaya_id')))
            ->when($request->filled('commune_id'), fn ($q) => $q->where('commune_id', $request->query('commune_id')))
            ->when($request->filled('priority'), fn ($q) => $q->where('gtm_priority', $request->query('priority')))
            ->when($request->filled('q'), fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', '%'.$request->query('q').'%')
                    ->orWhere('code', 'like', '%'.$request->query('q').'%')
            ))
            // Highest GTM priority first so the vente team sees what to push on top.
            ->orderByRaw("FIELD(gtm_priority, 'critical', 'high', 'medium', 'low')")
            ->orderBy('name')
            ->get();

        return LocationResource::collection($locations);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location->load(['wilaya', 'commune', 'type', 'contractType', 'paymentMethods']));
    }

    /** Read-only inventory funnel, pipeline and (permission-gated) revenue. */
    public function insights(Request $request, Location $location, BuildLocationInsights $action): JsonResponse
    {
        $canSeeMoney = (bool) ($request->user()?->can('versements.view')
            || $request->user()?->can('reports.view'));

        return response()->json(['data' => $action->handle($location, $canSeeMoney)]);
    }

    public function store(StoreLocationRequest $request, CreateLocation $action): LocationResource
    {
        return new LocationResource($action->handle($request->validated())->load(['wilaya', 'commune', 'type', 'contractType', 'paymentMethods']));
    }

    public function update(UpdateLocationRequest $request, Location $location, UpdateLocation $action): LocationResource
    {
        return new LocationResource($action->handle($location, $request->validated())->load(['wilaya', 'commune', 'type', 'contractType', 'paymentMethods']));
    }

    public function destroy(Request $request, Location $location, CancelLocation $action): LocationResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new LocationResource($action->handle($location, $reason));
    }

    /** Archive the project + its inventory (reversible; hidden until reactivated). */
    public function archive(Location $location, ArchiveLocation $action): LocationResource
    {
        return new LocationResource($action->handle($location)->load(['wilaya', 'commune', 'type', 'contractType', 'paymentMethods']));
    }

    /** Bring an archived project (and the inventory archived with it) back to active. */
    public function reactivate(Location $location, ReactivateLocation $action): LocationResource
    {
        return new LocationResource($action->handle($location)->load(['wilaya', 'commune', 'type', 'contractType', 'paymentMethods']));
    }
}
