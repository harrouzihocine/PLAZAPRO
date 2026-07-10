<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Support\NotificationLink;
use App\Modules\Pipeline\Enums\NextActionApproval;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Events\VisitAssigned;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A dispatcher's verdict on a beyond-window office-visit plan:
 *
 *  - approve     → the plan stands; its visit — kept quiet while pending — is
 *                  announced now (VisitAssigned), exactly like an in-window one;
 *  - deny        → the plan and its visit are cancelled with the manager's
 *                  reason; the agent must come back with a closer date;
 *  - reschedule  → the manager answers with their own date: the plan is
 *                  superseded via CorrectNextAction (history kept, visit
 *                  re-materialized) — the corrected plan is the dispatcher's
 *                  own, so it needs no approval and announces immediately.
 *
 * Either way the requester and the plan's assignee are told the outcome.
 */
class DecideOfficeVisitApproval
{
    public function __construct(private CorrectNextAction $correctNextAction) {}

    /**
     * @param  array{decision: string, reason?: ?string, due_date?: ?string, due_time?: ?string}  $data
     */
    public function handle(NextAction $action, User $decider, array $data): NextAction
    {
        abort_unless(
            $action->type === NextActionType::OfficeVisit
                && $action->state === NextActionState::Pending
                && ! $action->isCancelled()
                && $action->approval_status === NextActionApproval::Pending,
            422,
            'This office-visit request has already been decided.',
        );

        return match ($data['decision']) {
            'approve' => $this->approve($action, $decider),
            'deny' => $this->deny($action, $decider, (string) $data['reason']),
            'reschedule' => $this->reschedule($action, $decider, $data),
        };
    }

    private function approve(NextAction $action, User $decider): NextAction
    {
        DB::transaction(function () use ($action, $decider) {
            $action->update([
                'approval_status' => NextActionApproval::Approved->value,
                'approval_decided_by' => $decider->id,
                'approval_decided_at' => now(),
            ]);

            // Announce the visit the pending flag kept quiet — the agent, the
            // project's contributors and the pipeline overseers hear about it
            // now, through the one normal channel (SendVisitAssignedNotification).
            $action->visits()->active()->whereNull('completed_at')->get()
                ->each(fn (Visit $visit) => VisitAssigned::dispatch($visit));
        });

        $this->notifyDecision($action, $decider, 'office_visit_approved', $action->due_at);

        return $action;
    }

    private function deny(NextAction $action, User $decider, string $reason): NextAction
    {
        DB::transaction(function () use ($action, $decider, $reason) {
            $action->update([
                'approval_status' => NextActionApproval::Denied->value,
                'approval_decided_by' => $decider->id,
                'approval_decided_at' => now(),
                'approval_reason' => $reason,
            ]);

            // The visit never happened — retire it and the plan with the
            // manager's reason. The project deliberately loses its pending
            // action: the agent is told to come back with a closer date, and
            // the stuck-projects monitor catches the thread if they don't.
            $action->visits()->active()->whereNull('completed_at')->get()
                ->each->cancel('Office visit denied: '.$reason);

            $action->cancel('Denied by manager: '.$reason);
        });

        $this->notifyDecision($action, $decider, 'office_visit_denied', $action->due_at, $reason);

        return $action;
    }

    /**
     * @param  array{due_date?: ?string, due_time?: ?string}  $data
     */
    private function reschedule(NextAction $action, User $decider, array $data): NextAction
    {
        $corrected = DB::transaction(function () use ($action, $decider, $data) {
            $action->update([
                'approval_status' => NextActionApproval::Rescheduled->value,
                'approval_decided_by' => $decider->id,
                'approval_decided_at' => now(),
            ]);

            return $this->correctNextAction->handle($action, [
                'type' => NextActionType::OfficeVisit->value,
                'due_date' => $data['due_date'],
                'due_time' => $data['due_time'] ?? null,
            ], 'Rescheduled by the manager', $decider);
        });

        $this->notifyDecision($action, $decider, 'office_visit_rescheduled', $corrected->due_at);

        return $corrected;
    }

    /**
     * Tell the people who live with the outcome — the requester and the plan's
     * assignee (deduped; never the decider, they just clicked the button).
     */
    private function notifyDecision(
        NextAction $action,
        User $decider,
        string $key,
        ?Carbon $when,
        ?string $reason = null,
    ): void {
        $action->loadMissing(['assignedTo', 'approvalRequestedBy', 'subject']);

        $subject = $action->subject;
        $clientName = ($subject instanceof ClientProject ? $subject->client : $subject)
            ?->full_name ?: 'a client';

        [$link, $subjectType, $subjectId] = NotificationLink::forSubject($subject);

        $date = $when === null ? '' : $when->format(
            $when->hour === 0 && $when->minute === 0 ? 'D d M' : 'D d M, H:i',
        );

        $recipients = collect([$action->approvalRequestedBy, $action->assignedTo])
            ->filter()
            ->unique('id')
            ->reject(fn (User $u) => $u->id === $decider->id);

        foreach ($recipients as $user) {
            $user->notify(new DomainNotification(
                kind: $key,
                key: $key,
                params: [
                    'client' => $clientName,
                    'date' => $date,
                    'manager' => $decider->name,
                    'reason' => $reason ?? '',
                ],
                link: $link,
                subjectType: $subjectType,
                subjectId: $subjectId,
            ));
        }
    }
}
