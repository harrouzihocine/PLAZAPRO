<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * The commercial state of a unit or box — distinct from the base record `status`
 * (active/cancelled). A cancelled record is a data correction, not a sale outcome.
 *
 * Precedence (strongest wins the single sale_status scalar): sold > onhold >
 * reserved > available. A unit can be On Hold for one project AND reserved by
 * others as backups at the same time — sale_status shows the strongest state and
 * the "Reserved N" count is derived from the active reservations (see Unit).
 * (Boxes only ever use available / reserved / sold — On Hold is unit-level.)
 */
enum SaleStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case OnHold = 'onhold';
    case Sold = 'sold';

    /** Higher = stronger lock; used to reason about which state a unit shows. */
    public function precedence(): int
    {
        return match ($this) {
            self::Available => 0,
            self::Reserved => 1,
            self::OnHold => 2,
            self::Sold => 3,
        };
    }
}
