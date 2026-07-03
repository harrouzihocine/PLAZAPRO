<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\CloseDeal;
use App\Modules\Clients\Actions\CreateDeal;
use App\Modules\Clients\Actions\SyncDealBoxes;
use App\Modules\Clients\Http\Requests\CloseDealRequest;
use App\Modules\Clients\Http\Requests\StoreDealRequest;
use App\Modules\Clients\Http\Requests\SyncDealBoxesRequest;
use App\Modules\Clients\Http\Resources\DealResource;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Deals on a client project. One active (reserved) deal at a time; created from a
 * visit log (or directly with deals.direct); closed won / lost with clients.manage.
 */
class DealController extends Controller
{
    /** Only active items (a box removed from the deal stays in history, hidden). */
    private static function relations(): array
    {
        return [
            'items' => fn ($q) => $q->active(),
            'items.unit.type', 'items.unit.floor', 'items.unit.location', 'items.box.type',
        ];
    }

    public function index(ClientProject $project): AnonymousResourceCollection
    {
        return DealResource::collection(
            $project->deals()->active()->with(self::relations())->latest('id')->get(),
        );
    }

    public function store(StoreDealRequest $request, ClientProject $project, CreateDeal $action): DealResource
    {
        $deal = $action->handle($project, $request->validated(), $request->user());

        return new DealResource($deal->load(self::relations()));
    }

    public function close(CloseDealRequest $request, Deal $deal, CloseDeal $action): DealResource
    {
        $closed = $action->handle(
            $deal,
            $request->validated('outcome'),
            $request->validated('total_price'),
        );

        return new DealResource($closed->load(self::relations()));
    }

    /** Re-set the boxes reserved alongside the deal's apartment(s). */
    public function syncBoxes(SyncDealBoxesRequest $request, Deal $deal, SyncDealBoxes $action): DealResource
    {
        $updated = $action->handle($deal, $request->validated('box_ids'));

        return new DealResource($updated->load(self::relations()));
    }
}
