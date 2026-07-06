<?php

declare(strict_types=1);

namespace App\Modules\Clients\Enums;

/**
 * A deal's lifecycle. Born `open` (its properties are auto-marked Interested on
 * creation), then closed `won` (the client buys) or `lost` (the client passes,
 * the properties are released back to inventory).
 */
enum DealState: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
