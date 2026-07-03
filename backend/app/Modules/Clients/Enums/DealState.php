<?php

declare(strict_types=1);

namespace App\Modules\Clients\Enums;

/**
 * A deal's lifecycle. Born `reserved` (its properties are auto-reserved on
 * creation), then closed `won` (the client buys) or `lost` (the client passes,
 * the properties are released back to inventory).
 */
enum DealState: string
{
    case Reserved = 'reserved';
    case Won = 'won';
    case Lost = 'lost';

    public function isClosed(): bool
    {
        return $this !== self::Reserved;
    }
}
