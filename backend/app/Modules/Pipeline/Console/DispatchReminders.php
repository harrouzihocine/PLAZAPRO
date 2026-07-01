<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Console;

use App\Modules\Pipeline\Actions\DispatchReminders as DispatchRemindersAction;
use Illuminate\Console\Command;

/**
 * Deliver due pending reminders (in_app). Scheduled every minute (see
 * routes/console.php).
 */
class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Deliver due pending reminders to their assigned agents';

    public function handle(DispatchRemindersAction $action): int
    {
        $count = $action->handle();
        $this->info("Dispatched {$count} reminder(s).");

        return self::SUCCESS;
    }
}
