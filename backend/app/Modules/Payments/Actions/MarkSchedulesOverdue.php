<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\ScheduleState;
use App\Modules\Payments\Models\PaymentSchedule;

/**
 * The scheduled sweeper: any active instalment still owing (pending/partial) whose
 * due_date has passed is flipped to overdue. Runs on the scheduler (see
 * routes/console.php). Returns the number of instalments flipped.
 */
class MarkSchedulesOverdue
{
    public function handle(): int
    {
        $flipped = 0;

        PaymentSchedule::query()
            ->active()
            ->whereIn('state', [ScheduleState::Pending->value, ScheduleState::Partial->value])
            ->whereDate('due_date', '<', now())
            ->chunkById(200, function ($schedules) use (&$flipped) {
                foreach ($schedules as $schedule) {
                    // Per-row save so the transition is audited (LogsActivity).
                    $schedule->update(['state' => ScheduleState::Overdue->value]);
                    $flipped++;
                }
            });

        return $flipped;
    }
}
