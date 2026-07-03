<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Models\NextAction;
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
     * @param  array<string, mixed>  $data  type, due_date + optional due_time, assigned_to
     */
    public function handle(NextAction $action, array $data, string $reason): NextAction
    {
        return DB::transaction(function () use ($action, $data, $reason) {
            $corrected = $action->supersedeWith([
                'type' => $data['type'],
                'due_at' => CreateNextAction::resolveDueAt($data),
                'assigned_to' => $data['assigned_to'] ?? $action->assigned_to,
                'state' => NextActionState::Pending->value,
                'completed_at' => null,
            ], $reason);

            // The old plan's visits that never happened are retired with the same
            // reason (completed ones are history and stay untouched).
            $action->visits()->active()->whereNull('completed_at')->get()
                ->each->cancel($reason);

            // A visit-type next step IS the scheduling — materialize the visit(s).
            $this->syncVisitFromNextAction->handle($corrected);

            return $corrected;
        });
    }
}
