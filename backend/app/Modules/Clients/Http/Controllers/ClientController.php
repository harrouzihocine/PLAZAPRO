<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Modules\Clients\Actions\AssignClientAgent;
use App\Modules\Clients\Actions\CancelClient;
use App\Modules\Clients\Actions\CreateClient;
use App\Modules\Clients\Actions\RequestDuplicateResolution;
use App\Modules\Clients\Actions\UpdateClient;
use App\Modules\Clients\Http\Requests\AssignClientAgentRequest;
use App\Modules\Clients\Http\Requests\StoreClientRequest;
use App\Modules\Clients\Http\Requests\UpdateClientRequest;
use App\Modules\Clients\Http\Resources\ClientResource;
use App\Modules\Clients\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Clients. Reads require clients.view; create requires clients.create; edit
 * requires clients.edit; reassign requires clients.manage; cancel requires
 * clients.cancel (see routes). Thin — logic lives in the Actions.
 */
class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $clients = Client::query()
            // clients.view_all: without it, only own (created / assigned) clients.
            ->visibleTo($request->user())
            ->with(['source', 'rating', 'assignedAgent', 'creator', 'wilaya', 'commune'])
            ->withExists(['calls' => fn ($q) => $q->active()])
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('assigned_agent_id'), fn ($q) => $q->where('assigned_agent_id', $request->integer('assigned_agent_id')))
            ->when($request->filled('source_id'), fn ($q) => $q->where('source_id', $request->integer('source_id')))
            ->when($request->filled('rating_id'), fn ($q) => $q->where('rating_id', $request->integer('rating_id')))
            ->when($request->filled('wilaya_id'), fn ($q) => $q->where('wilaya_id', $request->integer('wilaya_id')))
            ->when($request->filled('commune_id'), fn ($q) => $q->where('commune_id', $request->integer('commune_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->query('search'));
                // Phone match is format-agnostic: compare digits only and ignore the
                // leading trunk "0" so any fragment matches regardless of how the
                // number (or the search term) is written — "+213555…", "0555…", "555…".
                $digits = ltrim(preg_replace('/\D/', '', $term), '0');
                // Name match is similarity-tolerant: every typed word must appear
                // somewhere in the WHOLE name, in any order — so "ahmed benali",
                // "benali ahmed" and a partial "ben" all hit (the old first/last
                // substring missed full-name searches). A phonetic (SOUNDS LIKE)
                // pass catches near-spellings — but ONLY for pure-Latin terms:
                // SOUNDEX is empty for Arabic script, which would otherwise make
                // one Arabic query match every Arabic name.
                $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);
                $full = "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";
                $phonetic = preg_match('/[A-Za-z]/', $term) && ! preg_match('/[^\x00-\x7F]/', $term);
                $q->where(function ($sub) use ($term, $digits, $words, $full, $phonetic) {
                    if ($words !== []) {
                        $sub->where(function ($name) use ($words, $full) {
                            foreach ($words as $w) {
                                $name->whereRaw("{$full} LIKE ?", ['%'.$w.'%']);
                            }
                        });
                        if ($phonetic) {
                            $sub->orWhereRaw("{$full} SOUNDS LIKE ?", [$term]);
                        }
                    }
                    if ($digits !== '') {
                        $sub->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$digits}%"]);
                    } else {
                        $sub->orWhere('phone', 'like', "%{$term}%");
                    }
                });
            })
            // Newest clients first — a name is found via search, not by scanning.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            // Server-side pagination: the list grows unbounded, so never ship the
            // whole table. per_page is capped so a client can't ask for everything.
            ->paginate(max(1, min((int) $request->query('per_page', 25), 100)))
            ->withQueryString();

        return ClientResource::collection($clients);
    }

    public function show(Request $request, Client $client): ClientResource
    {
        // Same visibility rule as the listing — an out-of-scope id reads as absent.
        abort_unless(
            Client::query()->visibleTo($request->user())->whereKey($client->id)->exists(),
            404,
        );

        return new ClientResource(
            $client->load(['source', 'rating', 'assignedAgent', 'creator', 'wilaya', 'commune'])
                ->loadExists(['calls' => fn ($q) => $q->active()]),
        );
    }

    public function store(
        StoreClientRequest $request,
        CreateClient $action,
        RequestDuplicateResolution $duplicate,
    ): JsonResponse {
        $user = $request->user();

        // Duplicate-phone guard: a phone identifies a client, so two clients with
        // the same number cannot coexist. If one already exists, block creation —
        // and, when the finder can't see it, open a supervised share request so
        // nobody silently takes another user's client.
        $existing = Client::query()->active()->matchingPhone($request->validated('phone'))->first();

        if ($existing !== null) {
            if (Client::query()->visibleTo($user)->whereKey($existing->id)->exists()) {
                return response()->json([
                    'duplicate' => true,
                    'client_id' => $existing->id,
                    'message' => __('app.duplicate_own'),
                ], 409);
            }

            $created = $duplicate->handle($existing, $request->validated(), $user);

            return response()->json([
                'duplicate' => true,
                'request_id' => $created->id,
                'message' => __('app.duplicate_other'),
            ], 409);
        }

        $client = $action->handle($request->validated())
            ->load(['source', 'rating', 'assignedAgent', 'creator', 'wilaya', 'commune']);

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function update(UpdateClientRequest $request, Client $client, UpdateClient $action): ClientResource
    {
        return new ClientResource(
            $action->handle($client, $request->validated())->load(['source', 'rating', 'assignedAgent', 'creator', 'wilaya', 'commune']),
        );
    }

    public function destroy(Request $request, Client $client, CancelClient $action): ClientResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new ClientResource($action->handle($client, $reason));
    }

    /**
     * Delegate a waiting client (a desire match) to a sales agent, who then runs
     * the reconnect. Focused endpoint (not a full client edit) so it can carry
     * its own side effect — notifying the assignee — and read as one intent in
     * the audit trail. Manager action (clients.manage, in the request).
     */
    public function assignAgent(AssignClientAgentRequest $request, Client $client, AssignClientAgent $action): ClientResource
    {
        return new ClientResource(
            $action->handle($client, $request->integer('agent_id'))->load('assignedAgent'),
        );
    }
}
