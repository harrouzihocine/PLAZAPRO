<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Pipeline\Actions\AssignDispatchItem;
use App\Modules\Pipeline\Events\VisitDeclined;
use App\Modules\Pipeline\Events\VisitLifecycleUpdated;
use App\Modules\Pipeline\Http\Requests\DeclineVisitRequest;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * The assigned agent walking their own in-site visit along the dispatch
 * lifecycle: accept → en route → arrived (the geofence usually beats the
 * manual tap) — or decline, which returns the plan to the dispatch pool with
 * the reason. Each stamp is idempotent (set once, replays are no-ops) so the
 * offline outbox can retry them blindly.
 */
class VisitLifecycleController extends Controller
{
    public function accept(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeOwn($request, $visit);

        $stamps = ['accepted_at' => $visit->accepted_at ?? now()];

        // Accepting an IMMINENT visit means "I'm going now" — save the second
        // tap. A far-future slot stays accepted-only: precision GPS must not
        // burn the battery all day (motion detection flips it once the drive
        // actually starts — RecordAgentPosition).
        if ($visit->en_route_at === null && $this->isImminent($visit)) {
            $stamps['en_route_at'] = now();
        }

        return $this->stamp($visit, $stamps);
    }

    /** Untimed-today (midnight sentinel), overdue, or due within the hour. */
    private function isImminent(Visit $visit): bool
    {
        $at = $visit->scheduled_at;

        if ($at->hour === 0 && $at->minute === 0) {
            return $at->isToday() || $at->isPast();
        }

        return $at->lte(now()->addMinutes(60));
    }

    public function enRoute(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeOwn($request, $visit);

        return $this->stamp($visit, [
            'accepted_at' => $visit->accepted_at ?? now(),
            'en_route_at' => $visit->en_route_at ?? now(),
        ]);
    }

    /** Manual fallback for a phone whose GPS never crossed the fence. */
    public function arrived(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeOwn($request, $visit);

        return $this->stamp($visit, [
            'accepted_at' => $visit->accepted_at ?? now(),
            'en_route_at' => $visit->en_route_at ?? now(),
            'arrived_at' => $visit->arrived_at ?? now(),
        ]);
    }

    public function decline(DeclineVisitRequest $request, Visit $visit, AssignDispatchItem $mover): JsonResponse
    {
        abort_if(
            $visit->next_action_id === null,
            422,
            'This visit has no open plan to return — ask the dispatcher to reassign it.',
        );

        DB::transaction(function () use ($request, $visit, $mover) {
            // The why, stamped on the row BEFORE the pool-return cancels it —
            // history keeps both the decline and its reason forever.
            $visit->update([
                'declined_at' => now(),
                'decline_reason' => $request->validated('reason'),
            ]);

            // Same path as the board's drag-to-pool: the plan loses its agent,
            // open visits retire, dispatchers get the standard "plan awaits
            // dispatch" signal (and the WHY via VisitDeclined below). Aborts
            // (plan already closed) roll the decline stamps back with it.
            $mover->handle(['kind' => 'visit', 'id' => $visit->id, 'agent_id' => null]);
        });

        VisitDeclined::dispatch($visit->fresh(), (int) $request->user()->id, $request->validated('reason'));

        return response()->json(['data' => ['declined' => true]]);
    }

    private function authorizeOwn(Request $request, Visit $visit): void
    {
        abort_unless((int) $visit->agent_id === (int) $request->user()->id, 403);
        abort_if($visit->completed_at !== null, 422, 'This visit is already completed.');
        abort_unless($visit->type->value === 'in_site', 422, 'Only in-site visits follow the dispatch lifecycle.');
    }

    /**
     * @param  array<string, mixed>  $stamps
     */
    private function stamp(Visit $visit, array $stamps): JsonResponse
    {
        $before = $visit->dispatchStatus();
        $visit->update($stamps);
        $visit->refresh();

        if ($visit->dispatchStatus() !== $before) {
            VisitLifecycleUpdated::dispatch($visit);
        }

        return response()->json(['data' => [
            'id' => $visit->id,
            'status' => $visit->dispatchStatus(),
            'accepted_at' => $visit->accepted_at,
            'en_route_at' => $visit->en_route_at,
            'arrived_at' => $visit->arrived_at,
        ]]);
    }
}
