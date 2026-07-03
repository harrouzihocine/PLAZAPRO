<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One drag-and-drop move on the dispatch board, applied transactionally:
 *
 *  - pending plan → agent/day : assign the in-site next action and materialize
 *    its field visits (VisitAssigned notifies the agent + contributors);
 *  - visit → other agent/day  : re-schedule and re-assign the visit;
 *  - visit → pending          : un-assign — the visit(s) of its plan are retired
 *    and the plan returns to the pool (dispatchers re-notified).
 *
 * Moves only land on today or later — the past is history, not a schedule.
 */
class AssignDispatchItem
{
    public function __construct(
        private SyncVisitFromNextAction $syncVisitFromNextAction,
        private AssignVisit $assignVisit,
    ) {}

    /**
     * @param  array{kind: string, id: int, agent_id?: int|null, due_date?: string|null}  $change
     */
    public function handle(array $change): void
    {
        // Compare whole days (app timezone): a date-only due_date is midnight
        // already, but startOfDay() keeps the guard correct even if a time slips in.
        $dueDate = isset($change['due_date']) ? Carbon::parse($change['due_date'])->startOfDay() : null;

        abort_if(
            $dueDate !== null && $dueDate->isBefore(now()->startOfDay()),
            422,
            'Tasks can only be scheduled from today onwards.',
        );

        DB::transaction(function () use ($change, $dueDate) {
            match ($change['kind']) {
                'action' => $this->moveAction($change, $dueDate),
                'visit' => $this->moveVisit($change, $dueDate),
            };
        });
    }

    private function moveAction(array $change, ?Carbon $dueDate): void
    {
        $action = NextAction::query()->active()->pending()->findOrFail($change['id']);

        abort_unless($action->type->value === 'in_site_visit', 422, 'Only in-site plans are dispatched from the board.');

        $updates = [];
        if (array_key_exists('agent_id', $change)) {
            $updates['assigned_to'] = $change['agent_id'];
        }
        if ($dueDate !== null) {
            // The board drops on a DAY; the concrete hour is the agent's to plan.
            $updates['due_at'] = $dueDate->setTime(9, 0);
        }
        $action->update($updates);
        $action->refresh();

        // Un-assigned (returned to the pool): retire its open visits.
        if ($action->assigned_to === null) {
            $action->visits()->active()->whereNull('completed_at')->get()
                ->each->cancel('Returned to the dispatch pool');

            return;
        }

        // Assigned: visits already materialized from this plan FOLLOW it — a
        // re-assignment must move them too (GenerateInSiteVisits would skip
        // them as "already open" and leave them on the previous agent).
        $openVisits = $action->visits()->active()->whereNull('completed_at')->get();

        foreach ($openVisits as $visit) {
            $visit->update(['scheduled_at' => $action->due_at]);
            if ((int) $visit->agent_id !== (int) $action->assigned_to) {
                $this->assignVisit->handle($visit->fresh(), (int) $action->assigned_to);
            }
        }

        // First assignment (or new shortlisted units): materialize the rest.
        $this->syncVisitFromNextAction->handle($action);
    }

    private function moveVisit(array $change, ?Carbon $dueDate): void
    {
        $visit = Visit::query()->active()->whereNull('completed_at')->findOrFail($change['id']);

        abort_unless($visit->type->value === 'in_site', 422, 'Only in-site visits are dispatched from the board.');

        // Back to the pool: the plan loses its agent, its open visits retire.
        if (empty($change['agent_id'])) {
            $action = $visit->next_action_id
                ? NextAction::query()->active()->pending()->find($visit->next_action_id)
                : null;
            abort_if($action === null, 422, 'This visit has no open plan to return to the pool.');

            $action->update(['assigned_to' => null]);
            $action->visits()->active()->whereNull('completed_at')->get()
                ->each->cancel('Returned to the dispatch pool');
            $this->syncVisitFromNextAction->handle($action->fresh());

            return;
        }

        if ($dueDate !== null) {
            $time = $visit->scheduled_at;
            $visit->update([
                'scheduled_at' => $dueDate->copy()->setTime((int) ($time?->format('H') ?? 9), (int) ($time?->format('i') ?? 0)),
            ]);
        }

        if ((int) $change['agent_id'] !== (int) $visit->agent_id) {
            $this->assignVisit->handle($visit->fresh(), (int) $change['agent_id']);
        }
    }
}
