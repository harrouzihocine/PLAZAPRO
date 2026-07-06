<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\SyncShortlist;
use App\Modules\Clients\Http\Requests\SyncShortlistRequest;
use App\Modules\Clients\Http\Resources\ShortlistItemResource;
use App\Modules\Clients\Models\ClientProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The property shortlist on a deal, built at the office visit. Reading needs
 * clients.view; curating it from the standalone panel needs shortlist.manage
 * (agents add properties via "Add unit to visit" instead).
 */
class ShortlistController extends Controller
{
    public function index(Request $request, ClientProject $project): AnonymousResourceCollection
    {
        // A project outside the user's visibility scope reads as absent.
        abort_unless($project->isVisibleTo($request->user()), 404);

        // Eager-load the full property card per morph type (type/floor/location).
        $items = $project->shortlistItems()->active()->withProperty()->get();

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
}
