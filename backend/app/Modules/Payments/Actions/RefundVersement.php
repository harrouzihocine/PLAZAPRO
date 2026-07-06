<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Refund a done payment — the money went back to the client. Different from a
 * correction (CorrectVersement: the RECORD was wrong): here the record is
 * right, the cash flow reversed. The row is never cancelled or superseded — it
 * stays in history flagged refunded (who / when / why), and its contribution
 * to the instalment it settled is reversed. Typical after a won apartment is
 * released (ReleaseWonDealUnit) while its payments stay behind as history.
 */
class RefundVersement
{
    public function __construct(private AllocateVersement $allocate) {}

    public function handle(Versement $versement, string $reason, User $actor): Versement
    {
        abort_unless($versement->isActive(), 422, 'Only an active versement can be refunded.');
        abort_if($versement->isRefunded(), 422, 'This versement is already refunded.');

        return DB::transaction(function () use ($versement, $reason, $actor) {
            // Reverse the allocation: the instalment is no longer settled by
            // this money. Lock the row so the adjustment can't race others.
            if ($versement->schedule_item_id !== null) {
                $item = PaymentSchedule::query()->active()->lockForUpdate()
                    ->find($versement->schedule_item_id);
                if ($item !== null) {
                    $this->allocate->handle($item, Money::sub('0', (string) $versement->amount));
                }
            }

            $versement->update([
                'refunded_at' => now(),
                'refund_reason' => $reason,
                'refunded_by' => $actor->id,
            ]);

            return $versement;
        });
    }
}
