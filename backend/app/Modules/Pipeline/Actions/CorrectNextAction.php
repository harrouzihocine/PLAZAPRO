<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\NextActionApproval;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Events\OfficeVisitApprovalRequested;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Support\OfficeVisitWindow;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correct the enforced next action with a reason — e.g. the sales agent edits the
 * last log to change a "call" next step into an "in-site visit". The original is
 * cancelled and a new pending version is inserted (supersedeWith), so both stay in
 * the timeline. The cancelled original drops out of active()->pending(), keeping the
 * exactly-one-open-action invariant intact.
 *
 * Because visits are materialized FROM next actions, superseding a plan also
 * cancels its pending (un-completed) visits with the same reason, then
 * materializes the new plan — so call→visit corrections create the visit and
 * visit→call corrections retire it, with the full history kept.
 */
class CorrectNextAction
{
    public function __construct(private SyncVisitFromNextAction $syncVisitFromNextAction) {}

    /**
     * `$planner` (the user making the correction) re-runs the office-visit
     * window rule for the corrected version: pushing a plan beyond the window
     * is a fresh approval request, exactly like planning it there directly.
     *
     * @param  array<string, mixed>  $data  type, due_date + optional due_time, assigned_to
     */
    public function handle(NextAction $action, array $data, string $reason, ?User $planner = null): NextAction
    {
        $dueAt = CreateNextAction::resolveDueAt($data);
        $approvalStatus = OfficeVisitWindow::approvalStatusFor($planner, $data['type'] ?? null, $dueAt);

        return DB::transaction(function () use ($action, $data, $reason, $dueAt, $approvalStatus, $planner) {
            $corrected = $action->supersedeWith([
                'type' => $data['type'],
                'due_at' => $dueAt,
                'assigned_to' => $this->resolveAssignee($action, $data),
                'state' => NextActionState::Pending->value,
                'completed_at' => null,
                // supersedeWith replicates the original's attributes — the
                // approval trail must not ride along: recompute it for the
                // corrected plan and clear the old verdict fields.
                'approval_status' => $approvalStatus,
                'approval_requested_by' => $approvalStatus !== null ? $planner?->id : null,
                'approval_decided_by' => null,
                'approval_decided_at' => null,
                'approval_reason' => null,
            ], $reason);

            // The old plan's visits that never happened are retired with the same
            // reason (completed ones are history and stay untouched).
            $action->visits()->active()->whereNull('completed_at')->get()
                ->each->cancel($reason);

            // A visit-type next step IS the scheduling — materialize the visit(s).
            $this->syncVisitFromNextAction->handle($corrected);

            if ($corrected->approval_status === NextActionApproval::Pending) {
                OfficeVisitApprovalRequested::dispatch($corrected);
            }

            return $corrected;
        });
    }

    /**
     * Who owns the corrected plan. Non-visit types keep the old assignee when
     * the request leaves it blank. An IN-SITE plan must be held by a field
     * agent: a blank assignee — or an inherited one who is not an agent (e.g. a
     * call plan owned by a sales user corrected into an in-site visit) — routes
     * the plan to the dispatch pool instead, never to a non-agent.
     */
    private function resolveAssignee(NextAction $action, array $data): ?int
    {
        $assignee = $data['assigned_to'] ?? $action->assigned_to;

        if (($data['type'] ?? null) !== NextActionType::InSiteVisit->value) {
            return $assignee !== null ? (int) $assignee : null;
        }

        if ($assignee === null) {
            return null; // dispatch pool
        }

        $user = User::query()->with('role')->find($assignee);

        return $user !== null && $user->isAgent() ? (int) $assignee : null;
    }
}
