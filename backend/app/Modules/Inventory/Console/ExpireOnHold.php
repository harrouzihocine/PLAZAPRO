<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Actions\ExpireOnHoldUnits;
use Illuminate\Console\Command;

/**
 * Return On Hold units whose holding-deposit window lapsed to the market
 * (reserved if backups remain, else available) and notify the former holder.
 * Scheduled every five minutes (see routes/console.php).
 */
class ExpireOnHold extends Command
{
    protected $signature = 'onhold:expire';

    protected $description = 'Return On Hold units past their deposit window to the market and notify the holder';

    public function handle(ExpireOnHoldUnits $action): int
    {
        $count = $action->handle();
        $this->info("Returned {$count} on-hold unit(s) to the market.");

        return self::SUCCESS;
    }
}
