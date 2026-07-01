<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Actions\CreateDynamicListItem;
use App\Modules\Settings\Actions\DeactivateDynamicListItem;
use App\Modules\Settings\Actions\ReorderDynamicListItems;
use App\Modules\Settings\Actions\UpdateDynamicListItem;
use App\Modules\Settings\Http\Requests\ReorderDynamicListItemsRequest;
use App\Modules\Settings\Http\Requests\StoreDynamicListItemRequest;
use App\Modules\Settings\Http\Requests\UpdateDynamicListItemRequest;
use App\Modules\Settings\Http\Resources\DynamicListItemResource;
use App\Modules\Settings\Http\Resources\DynamicListResource;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Thin controller for dynamic-list items (admin only — settings.manage). All
 * mutations run through Actions so the no-delete / audit rules stay in one place.
 */
class DynamicListItemController extends Controller
{
    /** Admin: all items (including inactive) for a list, in display order. */
    public function index(DynamicList $list): AnonymousResourceCollection
    {
        return DynamicListItemResource::collection(
            $list->items()->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(StoreDynamicListItemRequest $request, DynamicList $list, CreateDynamicListItem $action): DynamicListItemResource
    {
        return new DynamicListItemResource($action->handle($list, $request->validated()));
    }

    public function update(UpdateDynamicListItemRequest $request, DynamicList $list, DynamicListItem $item, UpdateDynamicListItem $action): DynamicListItemResource
    {
        return new DynamicListItemResource($action->handle($list, $item, $request->validated()));
    }

    public function reorder(ReorderDynamicListItemsRequest $request, DynamicList $list, ReorderDynamicListItems $action): DynamicListResource
    {
        return new DynamicListResource($action->handle($list, $request->validated()['order']));
    }

    public function destroy(DynamicList $list, DynamicListItem $item, DeactivateDynamicListItem $action): DynamicListItemResource
    {
        return new DynamicListItemResource($action->handle($list, $item));
    }
}
