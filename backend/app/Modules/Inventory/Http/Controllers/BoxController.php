<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\CancelBox;
use App\Modules\Inventory\Actions\CreateBox;
use App\Modules\Inventory\Actions\UpdateBox;
use App\Modules\Inventory\Http\Requests\StoreBoxRequest;
use App\Modules\Inventory\Http\Requests\UpdateBoxRequest;
use App\Modules\Inventory\Http\Resources\BoxResource;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Boxes (parking / storage). Reads require units.view; writes require units.manage.
 */
class BoxController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $boxes = Box::query()
            ->with('type')
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->query('location_id')))
            ->when($request->filled('unit_id'), fn ($q) => $q->where('unit_id', $request->query('unit_id')))
            // unlinked=1 → only boxes not attached to any apartment (the pool a
            // deal can link to an apartment that has none).
            ->when($request->boolean('unlinked'), fn ($q) => $q->whereNull('unit_id'))
            ->when($request->filled('sale_status'), fn ($q) => $q->where('sale_status', $request->query('sale_status')))
            ->orderBy('reference')
            ->get();

        return BoxResource::collection($boxes);
    }

    public function store(StoreBoxRequest $request, Location $location, CreateBox $action): BoxResource
    {
        return new BoxResource($action->handle($location, $request->validated())->load('type'));
    }

    public function update(UpdateBoxRequest $request, Box $box, UpdateBox $action): BoxResource
    {
        return new BoxResource($action->handle($box, $request->validated())->load('type'));
    }

    public function destroy(Request $request, Box $box, CancelBox $action): BoxResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new BoxResource($action->handle($box, $reason));
    }
}
