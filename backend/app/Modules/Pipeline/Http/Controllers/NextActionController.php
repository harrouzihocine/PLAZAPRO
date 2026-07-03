<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Actions\CorrectNextAction;
use App\Modules\Pipeline\Actions\CreateNextAction;
use App\Modules\Pipeline\Actions\SyncVisitFromNextAction;
use App\Modules\Pipeline\Http\Requests\CorrectNextActionRequest;
use App\Modules\Pipeline\Http\Requests\StoreNextActionRequest;
use App\Modules\Pipeline\Http\Resources\NextActionResource;
use App\Modules\Pipeline\Models\NextAction;
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

    public function correct(CorrectNextActionRequest $request, NextAction $nextAction, CorrectNextAction $action): NextActionResource
    {
        return new NextActionResource(
            $action->handle($nextAction, $request->validated(), $request->validated('reason'))
                ->load('assignedTo'),
        );
    }
}
