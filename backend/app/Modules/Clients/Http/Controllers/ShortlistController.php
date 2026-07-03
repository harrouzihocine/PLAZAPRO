<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\RecordShortlistOutcome;
use App\Modules\Clients\Actions\SyncShortlist;
use App\Modules\Clients\Http\Requests\ShortlistOutcomeRequest;
use App\Modules\Clients\Http\Requests\SyncShortlistRequest;
use App\Modules\Clients\Http\Resources\ShortlistItemResource;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The property shortlist on a deal, built at the office visit. Reading needs
 * clients.view; setting it needs visits.conduct (the visiting agent curates it).
 */
class ShortlistController extends Controller
{
    public function index(Request $request, ClientProject $project): AnonymousResourceCollection
    {
        // A project outside the user's visibility scope reads as absent.
        abort_unless($project->isVisibleTo($request->user()), 404);

        // Eager-load the full property card per morph type (type/floor/location).
        $items = $project->shortlistItems()->active()
            ->with(['shortlistable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Unit::class => ['type', 'floor', 'location'],
                Box::class => ['type', 'location'],
            ])])
            ->get();

        return ShortlistItemResource::collection($items);
    }

    public function sync(SyncShortlistRequest $request, ClientProject $project, SyncShortlist $action): AnonymousResourceCollection
    {
        $items = $action->handle(
            $project,
            $request->validated('items'),
            $request->validated('office_visit_id'),
        );

        return ShortlistItemResource::collection($items);
    }

    /** Phase-6 closure: win (buy) or lose an interested shortlisted property. */
    public function outcome(ShortlistOutcomeRequest $request, ShortlistItem $item, RecordShortlistOutcome $action): ShortlistItemResource
    {
        $updated = $action->handle(
            $item,
            $request->validated('outcome'),
            $request->validated('total_price'),
        )->load('shortlistable');

        return new ShortlistItemResource($updated);
    }
}
