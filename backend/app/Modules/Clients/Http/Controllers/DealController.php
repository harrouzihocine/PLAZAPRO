<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\AddBoxesToWonUnit;
use App\Modules\Clients\Actions\BuildProjectParticipants;
use App\Modules\Clients\Actions\CloseDeal;
use App\Modules\Clients\Actions\CloseDealUnit;
use App\Modules\Clients\Actions\CreateDeal;
use App\Modules\Clients\Actions\ReleaseWonDealUnit;
use App\Modules\Clients\Actions\SetDealItemFinish;
use App\Modules\Clients\Actions\SyncDealUnitBoxes;
use App\Modules\Clients\Http\Requests\AddDealBoxesRequest;
use App\Modules\Clients\Http\Requests\CloseDealItemRequest;
use App\Modules\Clients\Http\Requests\CloseDealRequest;
use App\Modules\Clients\Http\Requests\ReleaseDealItemRequest;
use App\Modules\Clients\Http\Requests\StoreDealRequest;
use App\Modules\Clients\Http\Requests\SyncDealBoxesRequest;
use App\Modules\Clients\Http\Requests\UpdateDealItemFinishRequest;
use App\Modules\Clients\Http\Resources\DealResource;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\FinishType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Deals on a client project — a project may carry several at once (one per
 * apartment the client commits to); created from an interaction log — a visit
 * or a call — (or directly with deals.direct).
 * Each apartment on the deal closes won / lost on its own (deals.manage); the
 * deal resolves itself when the last one is decided.
 */
class DealController extends Controller
{
    /** Only active items (a box removed from the deal stays in history, hidden). */
    private static function relations(): array
    {
        return [
            'items' => fn ($q) => $q->active(),
            'items.unit.floor', 'items.unit.location', 'items.unit.location.type', 'items.box.type',
        ];
    }

    public function index(Request $request, ClientProject $project): AnonymousResourceCollection
    {
        // A project outside the user's visibility scope reads as absent.
        abort_unless($project->isVisibleTo($request->user()), 404);

        return DealResource::collection(
            $project->deals()->active()->with(self::relations())->latest('id')->get(),
        );
    }

    /** Who worked this project — the pool for the "who deserves credit" pickers. */
    public function participants(Request $request, ClientProject $project, BuildProjectParticipants $action): JsonResponse
    {
        abort_unless($project->isVisibleTo($request->user()), 404);

        return response()->json(['data' => $action->handle($project)]);
    }

    public function store(StoreDealRequest $request, ClientProject $project, CreateDeal $action): DealResource
    {
        $deal = $action->handle($project, $request->validated(), $request->user());

        return new DealResource($deal->load(self::relations()));
    }

    /** Close the WHOLE deal in one move (bulk face of the per-apartment close). */
    public function close(CloseDealRequest $request, Deal $deal, CloseDeal $action): DealResource
    {
        $closed = $action->handle(
            $deal,
            $request->validated('outcome'),
            $request->validated('items') ?? [],
            $request->validated('resolution'),
            $request->validated('note'),
        );

        return new DealResource($closed->load(self::relations()));
    }

    /** Close ONE apartment on the deal — won (own agreed price) or lost. */
    public function closeItem(
        CloseDealItemRequest $request,
        Deal $deal,
        DealItem $item,
        CloseDealUnit $action,
    ): DealResource {
        abort_unless((int) $item->deal_id === (int) $deal->id, 404);

        $closed = $action->handle(
            $item,
            $request->validated('outcome'),
            $request->validated('agreed_price'),
            $request->validated('resolution'),
            $request->validated('note'),
            [
                'sale' => $request->validated('sale_agent_ids') ?? [],
                'insite' => $request->validated('insite_agent_ids') ?? [],
                'other' => $request->validated('other_agent_ids') ?? [],
            ],
        );

        return new DealResource($closed->load(self::relations()));
    }

    /**
     * Release ONE WON apartment — the sale fell through even after the win.
     * The properties return to the market; payments stay as history.
     */
    public function releaseItem(
        ReleaseDealItemRequest $request,
        Deal $deal,
        DealItem $item,
        ReleaseWonDealUnit $action,
    ): DealResource {
        abort_unless((int) $item->deal_id === (int) $deal->id, 404);

        $released = $action->handle(
            $item,
            $request->validated('resolution') ?? 'reopen',
            $request->validated('note'),
        );

        return new DealResource($released->load(self::relations()));
    }

    /**
     * Sell extra boxes onto a WON apartment (the client comes back for a
     * parking / storage box) — the agreed price grows by the addition.
     */
    public function addBoxes(
        AddDealBoxesRequest $request,
        Deal $deal,
        DealItem $item,
        AddBoxesToWonUnit $action,
    ): DealResource {
        abort_unless((int) $item->deal_id === (int) $deal->id, 404);

        $updated = $action->handle(
            $item,
            array_map(intval(...), $request->validated('box_ids')),
            $request->validated('added_price'),
        );

        return new DealResource($updated->load(self::relations()));
    }

    /** Switch the finish (semi-fini / fini) the client takes ONE open apartment at. */
    public function updateItemFinish(
        UpdateDealItemFinishRequest $request,
        Deal $deal,
        DealItem $item,
        SetDealItemFinish $action,
    ): DealResource {
        abort_unless((int) $item->deal_id === (int) $deal->id, 404);

        $action->handle($item, FinishType::from($request->validated('finish_type')));

        return new DealResource($deal->fresh()->load(self::relations()));
    }

    /** Re-set the boxes riding with ONE apartment on the open deal. */
    public function syncUnitBoxes(
        SyncDealBoxesRequest $request,
        Deal $deal,
        DealItem $item,
        SyncDealUnitBoxes $action,
    ): DealResource {
        abort_unless((int) $item->deal_id === (int) $deal->id, 404);

        $updated = $action->handle($item, $request->validated('box_ids'));

        return new DealResource($updated->load(self::relations()));
    }
}
