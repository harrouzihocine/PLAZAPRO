<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Actions\ExpireReservedUnits;
use Illuminate\Console\Command;

/**
 * Return Reserved units whose holding-deposit window lapsed to the market
 * (interested if backups remain, else available) and notify the former holder.
 * Scheduled every five minutes (see routes/console.php).
 */
class ExpireReserved extends Command
{
    protected $signature = 'reserved:expire';

    protected $description = 'Return Reserved units past their deposit window to the market and notify the holder';

    public function handle(ExpireReservedUnits $action): int
    {
        $count = $action->handle();
        $this->info("Returned {$count} reserved unit(s) to the market.");

        return self::SUCCESS;
    }
}
