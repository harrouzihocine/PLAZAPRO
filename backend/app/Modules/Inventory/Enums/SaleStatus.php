<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * The commercial state of a unit or box — distinct from the base record `status`
 * (active/cancelled). A cancelled record is a data correction, not a sale outcome.
 *
 * Precedence (strongest wins the single sale_status scalar): sold > reserved >
 * interested > available. `interested` = one or more client projects hold the
 * unit (an open deal, or backup holds); `reserved` = a client paid a holding
 * deposit — the hard, single-holder off-market lock. A unit can be Reserved by
 * one project AND have others interested as backups at the same time —
 * sale_status shows the strongest state and the "Interested N" count is derived
 * from the active holds (see Unit). (Boxes only ever use available / interested
 * / sold — the deposit lock is unit-level.)
 */
enum SaleStatus: string
{
    case Available = 'available';
    case Interested = 'interested';
    case Reserved = 'reserved';
    case Sold = 'sold';

    /** Higher = stronger lock; used to reason about which state a unit shows. */
    public function precedence(): int
    {
        return match ($this) {
            self::Available => 0,
            self::Interested => 1,
            self::Reserved => 2,
            self::Sold => 3,
        };
    }
}
