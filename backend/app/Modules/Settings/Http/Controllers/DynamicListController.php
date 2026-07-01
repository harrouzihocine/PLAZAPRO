<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Actions\CancelDynamicList;
use App\Modules\Settings\Actions\CreateDynamicList;
use App\Modules\Settings\Actions\UpdateDynamicList;
use App\Modules\Settings\Http\Requests\StoreDynamicListRequest;
use App\Modules\Settings\Http\Requests\UpdateDynamicListRequest;
use App\Modules\Settings\Http\Resources\DynamicListResource;
use App\Modules\Settings\Models\DynamicList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Thin controller for the dynamic-list backbone. `show` is the read endpoint
 * every dropdown consumes (any authenticated user); the rest require
 * settings.manage (enforced by both the route and each FormRequest).
 */
class DynamicListController extends Controller
{
    /** Admin: every list, for the Lists admin screen. */
    public function index(): AnonymousResourceCollection
    {
        return DynamicListResource::collection(
            DynamicList::query()->orderBy('name')->get()
        );
    }

    /** Read endpoint every dropdown uses — active items only, in display order. */
    public function show(DynamicList $list): DynamicListResource
    {
        return new DynamicListResource($list->load('activeItems'));
    }

    public function store(StoreDynamicListRequest $request, CreateDynamicList $action): DynamicListResource
    {
        return new DynamicListResource($action->handle($request->validated()));
    }

    public function update(UpdateDynamicListRequest $request, DynamicList $list, UpdateDynamicList $action): DynamicListResource
    {
        return new DynamicListResource($action->handle($list, $request->validated()));
    }

    public function destroy(Request $request, DynamicList $list, CancelDynamicList $action): DynamicListResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new DynamicListResource($action->handle($list, $reason));
    }
}
