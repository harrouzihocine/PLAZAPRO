<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Support\Money;

/**
 * Allocate a (possibly negative) amount to a schedule instalment and re-derive its
 * paid_amount and state. Positive on record; negative when a correction reverses a
 * prior allocation. Called inside the caller's transaction — the server is the sole
 * source of truth for balances.
 */
class AllocateVersement
{
    public function handle(PaymentSchedule $item, string $delta): PaymentSchedule
    {
        $paidAmount = Money::add((string) $item->paid_amount, $delta);

        // Never let a reversal drive the balance negative.
        if (Money::compare($paidAmount, '0') < 0) {
            $paidAmount = '0.00';
        }

        $item->update([
            'paid_amount' => $paidAmount,
            'state' => $item->deriveState($paidAmount)->value,
        ]);

        return $item;
    }
}
