<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Console;

use App\Modules\Pipeline\Actions\GenerateDueReminders;
use Illuminate\Console\Command;

/**
 * Generate reminders for due/overdue pending next actions. Scheduled hourly
 * (see routes/console.php).
 */
class MarkActionsOverdue extends Command
{
    protected $signature = 'actions:mark-overdue';

    protected $description = 'Generate reminders for due/overdue pending next actions';

    public function handle(GenerateDueReminders $action): int
    {
        $count = $action->handle();
        $this->info("Generated {$count} reminder(s) for due next actions.");

        return self::SUCCESS;
    }
}
