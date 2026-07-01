<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Actions\MarkSchedulesOverdue;
use Illuminate\Console\Command;

/**
 * Flip past-due, still-owing instalments to overdue. Scheduled daily (see
 * routes/console.php). Requires a running scheduler in the container (Phase 7).
 */
class MarkSchedulesOverdueCommand extends Command
{
    protected $signature = 'schedules:mark-overdue';

    protected $description = 'Flip past-due, unpaid payment-schedule instalments to overdue';

    public function handle(MarkSchedulesOverdue $action): int
    {
        $count = $action->handle();
        $this->info("Marked {$count} instalment(s) overdue.");

        return self::SUCCESS;
    }
}
