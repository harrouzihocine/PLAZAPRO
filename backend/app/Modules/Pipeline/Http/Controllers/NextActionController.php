<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Actions\BuildAgentAgenda;
use App\Modules\Pipeline\Actions\CorrectNextAction;
use App\Modules\Pipeline\Actions\CreateNextAction;
use App\Modules\Pipeline\Actions\SyncVisitFromNextAction;
use App\Modules\Pipeline\Http\Requests\CorrectNextActionRequest;
use App\Modules\Pipeline\Http\Requests\StoreNextActionRequest;
use App\Modules\Pipeline\Http\Resources\NextActionResource;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Settings\Models\DynamicListItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * The next action, standalone. `store` plans one after the fact (next actions
 * are optional when logging, so a closed thread can be re-armed later);
 * `correct` edits the open one. Both keep full history — a correction is a
 * cancel + new pending version (CorrectNextAction → supersedeWith), never an
 * in-place edit.
 */
class NextActionController extends Controller
{
    public function store(
        StoreNextActionRequest $request,
        Client $client,
        CreateNextAction $create,
        SyncVisitFromNextAction $syncVisit,
    ): NextActionResource {
        // Same visibility rule as the client endpoints — a client outside the
        // caller's scope reads as absent, and cannot be planned on.
        abort_unless(
            Client::query()->visibleTo($request->user())->whereKey($client->id)->exists(),
            404,
        );

        $data = $request->validated();

        $subject = empty($data['client_project_id'])
            ? $client
            : ClientProject::findOrFail((int) $data['client_project_id']);

        if ($subject instanceof ClientProject) {
            abort_unless($subject->isActive(), 422, 'This project is closed — reactivate it before planning on it.');

            // An explicitly frozen project takes no new plans. (Open deals do
            // NOT freeze the logs — the client may keep hunting apartments.)
            abort_if($subject->isFrozen(), 422, 'This project is frozen — unfreeze it before planning on it.');
        }

        // One transaction: if materializing the visit(s) is rejected (e.g. an
        // in-site plan without a shortlisted unit), the plan creation — and the
        // closing of the prior pending plan — must roll back with it.
        $nextAction = DB::transaction(function () use ($create, $syncVisit, $subject, $data, $client, $request) {
            $nextAction = $create->handle(
                $subject, null, $data, $client->assigned_agent_id ?? $request->user()->id,
            );

            // A visit-type plan IS the scheduling — materialize the visit(s).
            $syncVisit->handle($nextAction);

            return $nextAction;
        });

        return new NextActionResource($nextAction->load('assignedTo'));
    }

    /**
     * The signed-in user's own workload for a week, per calendar day — feeds the
     * "when are you free?" strip on the next-action form so an agent schedules the
     * follow-up onto a lighter day. `from` shifts the window (the week picker looks
     * ahead); it defaults to today. Own data only; no extra grant.
     */
    public function agenda(Request $request, BuildAgentAgenda $action): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'days' => ['nullable', 'integer'],
        ]);

        $days = max(1, min((int) ($validated['days'] ?? 7), 14));
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])
            : CarbonImmutable::now();

        return response()->json([
            'data' => $action->handle($request->user(), $from, $days),
        ]);
    }

    public function correct(CorrectNextActionRequest $request, NextAction $nextAction, CorrectNextAction $action): NextActionResource
    {
        // The reason is a picked list label plus an optional free note — one
        // stored string, same shape as the archive/lost reason.
        $label = DynamicListItem::query()->whereKey($request->validated('reason_id'))->value('label');
        $note = $request->validated('note');
        $reason = trim($label.($note ? " — {$note}" : ''));

        return new NextActionResource(
            $action->handle($nextAction, $request->validated(), $reason)
                ->load('assignedTo'),
        );
    }
}
