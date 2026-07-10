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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Create the enforced next action for a subject (client/project), keeping the
 * invariant that a subject has **exactly one** open `pending` action: any prior
 * pending plan is closed first (ClosePendingNextActions). `source` is the
 * call/visit that produced it.
 *
 * "When" arrives as `due_date` + optional `due_time` (the UI splits them because an
 * agent usually only knows the day); a legacy `due_at` is still accepted so internal
 * callers (seeders) need not change. "Who" falls back to $defaultAssigneeId (the
 * client's sales agent) when the request left assigned_to blank.
 */
class CreateNextAction
{
    public function __construct(private ClosePendingNextActions $closePending) {}

    /**
     * `$planner` (the signed-in user creating the plan) feeds the office-visit
     * window rule: their beyond-window office plan is flagged for a manager's
     * approval — unless they hold visits.dispatch themselves. Console/seeder
     * callers leave it null and are never gated.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Model $subject, ?Model $source, array $data, ?int $defaultAssigneeId = null, ?User $planner = null): NextAction
    {
        // An in-site plan may stay UNASSIGNED — it lands in the dispatch pool,
        // where a visits.dispatch holder hands it to a field agent (weekly
        // board). Every other type needs an owner (defaulted to the client's
        // sales agent by the caller).
        $isInSite = ($data['type'] ?? null) === NextActionType::InSiteVisit->value;

        if ($isInSite) {
            $assignedTo = $data['assigned_to'] ?? null;
        } else {
            $assignedTo = $data['assigned_to'] ?? $defaultAssigneeId;
            abort_if($assignedTo === null, 422, 'A next action must be assigned to someone.');
        }

        // Only in-site plans carry a specific-apartment target (same / another) —
        // an empty pick is "the whole shortlist", stored as null.
        $targetUnitIds = $isInSite && ! empty($data['unit_ids'])
            ? array_values(array_unique(array_map('intval', $data['unit_ids'])))
            : null;

        $dueAt = self::resolveDueAt($data);

        // Beyond the office-visit window (and not a dispatcher's own plan) →
        // the plan is still created, flagged pending, and the dispatchers are
        // asked to approve / deny / reschedule it.
        $approvalStatus = OfficeVisitWindow::approvalStatusFor($planner, $data['type'] ?? null, $dueAt);

        return DB::transaction(function () use ($subject, $source, $data, $assignedTo, $targetUnitIds, $dueAt, $approvalStatus, $planner) {
            // Close any prior open plan so exactly one stays pending (fulfilled →
            // done; an undispatched pool plan → cancelled). Shared rule — see
            // ClosePendingNextActions.
            $this->closePending->handle($subject, 'Replaced by a new plan before dispatch');

            $action = NextAction::create([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'type' => $data['type'],
                'due_at' => $dueAt,
                'assigned_to' => $assignedTo,
                'target_unit_ids' => $targetUnitIds,
                'state' => NextActionState::Pending->value,
                'approval_status' => $approvalStatus,
                'approval_requested_by' => $approvalStatus !== null ? $planner?->id : null,
            ]);

            if ($action->approval_status === NextActionApproval::Pending) {
                OfficeVisitApprovalRequested::dispatch($action);
            }

            return $action;
        });
    }

    /**
     * Compose the timestamp from the split date + optional time. Falls back to a
     * legacy single `due_at` value when a caller supplies one directly. Shared with
     * CorrectNextAction so both build `due_at` the same way.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolveDueAt(array $data): Carbon
    {
        if (! empty($data['due_at'])) {
            return Carbon::parse($data['due_at']);
        }

        $date = Carbon::parse($data['due_date'])->startOfDay();

        if (! empty($data['due_time'])) {
            [$hour, $minute] = array_map('intval', explode(':', (string) $data['due_time']));
            $date->setTime($hour, $minute);
        }

        return $date;
    }
}
