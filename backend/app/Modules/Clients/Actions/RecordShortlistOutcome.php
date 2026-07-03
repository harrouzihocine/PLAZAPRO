<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Phase-6 closure per property. From the in-site "interested" shortlist rows the
 * user decides each one:
 *  - won  → the client buys this unit: the deal takes the unit + agreed price and
 *           moves to `won`, the unit is marked sold, and payments (financer) begin;
 *  - lost → the client passes on it.
 * Only a property the client actually visited-and-liked can be won/lost.
 */
class RecordShortlistOutcome
{
    public function handle(ShortlistItem $item, string $outcome, ?string $totalPrice = null): ShortlistItem
    {
        abort_unless(
            $item->state === ShortlistState::VisitedInterested,
            422,
            'Only a property the client visited and was interested in can be closed.',
        );

        if ($outcome === 'lost') {
            $item->update(['state' => ShortlistState::Lost->value]);

            return $item;
        }

        // Won.
        abort_unless($item->shortlistable_type === 'unit', 422, 'Only a unit can be sold as the deal.');
        abort_if($totalPrice === null, 422, 'An agreed total price is required to win the deal.');

        return DB::transaction(function () use ($item, $totalPrice) {
            $item->update(['state' => ShortlistState::Won->value]);

            $item->clientProject->update([
                'unit_id' => $item->shortlistable_id,
                'total_price' => $totalPrice,
                'stage' => ClientProjectStage::Won->value,
            ]);

            // The chosen unit leaves available inventory.
            Unit::query()->whereKey($item->shortlistable_id)
                ->update(['sale_status' => SaleStatus::Sold->value]);

            return $item->fresh();
        });
    }
}
