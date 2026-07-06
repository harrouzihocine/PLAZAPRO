<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * The lifecycle of an interest hold (the record behind the Interested status).
 * `active` holds auto-expire 48h after `held_at` unless converted or released
 * first.
 */
enum HoldStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Converted = 'converted';
    case Released = 'released';
}
