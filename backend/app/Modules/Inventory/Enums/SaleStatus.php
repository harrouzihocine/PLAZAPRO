<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * The commercial state of a unit or box — distinct from the base record `status`
 * (active/cancelled). A cancelled record is a data correction, not a sale outcome.
 */
enum SaleStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
}
