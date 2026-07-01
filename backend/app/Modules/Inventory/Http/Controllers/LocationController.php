<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\CancelLocation;
use App\Modules\Inventory\Actions\CreateLocation;
use App\Modules\Inventory\Actions\UpdateLocation;
use App\Modules\Inventory\Http\Requests\StoreLocationRequest;
use App\Modules\Inventory\Http\Requests\UpdateLocationRequest;
use App\Modules\Inventory\Http\Resources\LocationResource;
use App\Modules\Inventory\Models\Location;
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
        $locations = Location::query()
            ->with('area')
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('area_id'), fn ($q) => $q->where('area_id', $request->query('area_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', '%'.$request->query('q').'%')
                    ->orWhere('code', 'like', '%'.$request->query('q').'%')
            ))
            ->orderBy('name')
            ->get();

        return LocationResource::collection($locations);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location->load('area'));
    }

    public function store(StoreLocationRequest $request, CreateLocation $action): LocationResource
    {
        return new LocationResource($action->handle($request->validated())->load('area'));
    }

    public function update(UpdateLocationRequest $request, Location $location, UpdateLocation $action): LocationResource
    {
        return new LocationResource($action->handle($location, $request->validated())->load('area'));
    }

    public function destroy(Request $request, Location $location, CancelLocation $action): LocationResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new LocationResource($action->handle($location, $reason));
    }
}
