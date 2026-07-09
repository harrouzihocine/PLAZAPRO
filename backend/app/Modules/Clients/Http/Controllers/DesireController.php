<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\MatchDesireToInventory;
use App\Modules\Clients\Actions\UpsertDesire;
use App\Modules\Clients\Http\Requests\UpsertDesireRequest;
use App\Modules\Clients\Http\Resources\DesireResource;
use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Http\Resources\UnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * A client's desire and the inventory matches it produces. Reading needs
 * clients.view; capturing/updating the desire needs clients.create (lead
 * qualification); matches additionally need units.view (see routes).
 */
class DesireController extends Controller
{
    public function show(Client $client): JsonResponse
    {
        $desire = $client->desire()->with(['wilayas', 'communes', 'types', 'roomNumbers', 'contractTypes', 'floors', 'locations'])->first();

        return response()->json([
            'data' => $desire ? (new DesireResource($desire))->resolve() : null,
        ]);
    }

    public function upsert(UpsertDesireRequest $request, Client $client, UpsertDesire $action): JsonResponse
    {
        $desire = $action->handle($client, $request->validated())->load(['wilayas', 'communes', 'types', 'roomNumbers', 'contractTypes', 'floors', 'locations']);

        // Idempotent PUT: a first call creates the row (JsonResource would auto-send
        // 201), later calls update it. Return a consistent 200 either way.
        return (new DesireResource($desire))->response()->setStatusCode(200);
    }

    public function matches(Client $client, MatchDesireToInventory $action): AnonymousResourceCollection
    {
        $desire = $client->desire()->first();
        $units = $desire ? $action->handle($desire) : collect();

        return UnitResource::collection($units);
    }
}
