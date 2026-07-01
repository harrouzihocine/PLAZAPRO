<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Events\VersementRecorded;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Correct a recorded versement the ONLY legal way: cancel-and-duplicate. The
 * original is cancelled (with a reason) and a corrected row is inserted, linked
 * via supersedes_id (HasVersions::supersedeWith). Both rows stay in history and
 * the audit log records the cancel + duplicate. The correction also re-allocates:
 * the original's contribution to its instalment is reversed and the corrected
 * amount is allocated. All in one transaction. This is the guide's headline rule.
 */
class CorrectVersement
{
    public function __construct(private AllocateVersement $allocate) {}

    public function handle(Versement $versement, array $data): Versement
    {
        abort_unless($versement->isActive(), 422, 'Only an active versement can be corrected.');

        $replacement = DB::transaction(function () use ($versement, $data) {
            $originalAmount = (string) $versement->amount;
            $scheduleItemId = $versement->schedule_item_id;

            // A corrected versement's figures differ, so its old receipt no longer
            // applies — drop the link so a fresh document can be generated.
            $changes = Arr::only($data, ['amount', 'paid_on', 'method_id', 'reference']);
            $changes['document_id'] = null;
            $reason = $data['reason'];

            // Reverse the original allocation before superseding it. Lock the
            // instalment row so the paid_amount adjustment can't race other writes.
            if ($scheduleItemId !== null) {
                $item = PaymentSchedule::query()->active()->lockForUpdate()->find($scheduleItemId);
                if ($item !== null) {
                    $this->allocate->handle($item, Money::sub('0', $originalAmount));
                }
            }

            $replacement = $versement->supersedeWith($changes, $reason);

            // Allocate the corrected amount to the same instalment (preserved by replicate()).
            if ($replacement->schedule_item_id !== null) {
                $item = PaymentSchedule::query()->active()->lockForUpdate()->find($replacement->schedule_item_id);
                if ($item !== null) {
                    $this->allocate->handle($item, (string) $replacement->amount);
                }
            }

            return $replacement;
        });

        // A correction is still a payment event — notify the owning agent.
        VersementRecorded::dispatch($replacement);

        return $replacement;
    }
}
