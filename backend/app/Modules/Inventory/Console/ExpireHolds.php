<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Actions\ExpireReservationHolds;
use Illuminate\Console\Command;

/**
 * Expire interest holds past their 48h window and return their units to
 * available. Scheduled every five minutes (see routes/console.php).
 */
class ExpireHolds extends Command
{
    protected $signature = 'holds:expire';

    protected $description = 'Expire interest holds past their 48h window and free their units';

    public function handle(ExpireReservationHolds $action): int
    {
        $count = $action->handle();
        $this->info("Expired {$count} interest hold(s).");

        return self::SUCCESS;
    }
}
