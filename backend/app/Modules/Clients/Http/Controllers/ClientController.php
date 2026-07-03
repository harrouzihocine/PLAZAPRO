<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\CancelClient;
use App\Modules\Clients\Actions\CreateClient;
use App\Modules\Clients\Actions\UpdateClient;
use App\Modules\Clients\Http\Requests\StoreClientRequest;
use App\Modules\Clients\Http\Requests\UpdateClientRequest;
use App\Modules\Clients\Http\Resources\ClientResource;
use App\Modules\Clients\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Clients. Reads require clients.view; create requires clients.create; edit /
 * reassign / cancel require clients.manage (see routes). Thin — logic lives in
 * the Actions.
 */
class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $clients = Client::query()
            ->with(['source', 'rating', 'assignedAgent', 'creator'])
            ->withExists(['calls' => fn ($q) => $q->active()])
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('assigned_agent_id'), fn ($q) => $q->where('assigned_agent_id', $request->integer('assigned_agent_id')))
            ->when($request->filled('source_id'), fn ($q) => $q->where('source_id', $request->integer('source_id')))
            ->when($request->filled('rating_id'), fn ($q) => $q->where('rating_id', $request->integer('rating_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->query('search'));
                // Phone match is format-agnostic: compare digits only and ignore the
                // leading trunk "0" so any fragment matches regardless of how the
                // number (or the search term) is written — "+213555…", "0555…", "555…".
                $digits = ltrim(preg_replace('/\D/', '', $term), '0');
                $q->where(function ($sub) use ($term, $digits) {
                    $sub->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%");
                    if ($digits !== '') {
                        $sub->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$digits}%"]);
                    } else {
                        $sub->orWhere('phone', 'like', "%{$term}%");
                    }
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return ClientResource::collection($clients);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource(
            $client->load(['source', 'rating', 'assignedAgent', 'creator'])
                ->loadExists(['calls' => fn ($q) => $q->active()]),
        );
    }

    public function store(StoreClientRequest $request, CreateClient $action): ClientResource
    {
        return new ClientResource(
            $action->handle($request->validated())->load(['source', 'rating', 'assignedAgent', 'creator']),
        );
    }

    public function update(UpdateClientRequest $request, Client $client, UpdateClient $action): ClientResource
    {
        return new ClientResource(
            $action->handle($client, $request->validated())->load(['source', 'rating', 'assignedAgent', 'creator']),
        );
    }

    public function destroy(Request $request, Client $client, CancelClient $action): ClientResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new ClientResource($action->handle($client, $reason));
    }
}
