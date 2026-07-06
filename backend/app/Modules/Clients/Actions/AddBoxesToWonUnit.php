<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;
use App\Modules\Payments\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Sell extra boxes onto a WON apartment — the client comes back later for a
 * parking / storage box. No release-and-rewin dance: the box sells right onto
 * the won apartment (sold + linked, box_linked when the link is created here)
 * and the apartment's agreed price grows by the negotiated addition (defaults
 * to the boxes' list prices). The deal and project totals re-derive; payments
 * reconcile to the new total (re-plan the schedule to match).
 */
class AddBoxesToWonUnit
{
    /**
     * @param  list<int>  $boxIds
     */
    public function handle(DealItem $item, array $boxIds, ?string $addedPrice = null): Deal
    {
        $deal = $item->deal;

        abort_unless($deal->isActive(), 422, 'This deal is not active.');
        abort_unless($item->isActive() && $item->unit_id !== null, 422, 'This is not an apartment on the deal.');
        abort_unless($item->state === DealState::Won, 422, 'Boxes are added here onto a WON apartment (use the boxes editor while the deal is open).');
        abort_if($deal->clientProject->isFrozen(), 422, 'This project is frozen — unfreeze it before selling a box.');

        return DB::transaction(function () use ($deal, $item, $boxIds, $addedPrice) {
            $item->load('unit');

            $listTotal = '0.00';
            foreach (array_unique($boxIds) as $boxId) {
                $box = Box::query()->active()->whereKey($boxId)->lockForUpdate()->first();

                abort_if($box === null, 422, 'A selected box does not exist.');
                abort_unless(
                    (int) $box->location_id === (int) $item->unit->location_id,
                    422,
                    "Box {$box->reference} belongs to another location.",
                );
                abort_unless(
                    $box->unit_id === null || (int) $box->unit_id === (int) $item->unit_id,
                    422,
                    "Box {$box->reference} is linked to another apartment.",
                );
                abort_unless(
                    $box->sale_status === SaleStatus::Available,
                    422,
                    "Box {$box->reference} is not available.",
                );

                $linkedHere = $box->unit_id === null;
                $box->update([
                    'sale_status' => SaleStatus::Sold->value,
                    'unit_id' => $item->unit_id,
                ]);

                DealItem::create([
                    'deal_id' => $deal->id,
                    'box_id' => $box->id,
                    'parent_item_id' => $item->id,
                    'box_linked' => $linkedHere,
                    'state' => DealState::Won->value,
                    'closed_at' => now(),
                ]);

                $listTotal = Money::add($listTotal, (string) ($box->price ?? '0'));
            }

            $item->update([
                'agreed_price' => Money::add((string) $item->agreed_price, $addedPrice ?? $listTotal),
            ]);

            // A closed-won deal re-derives its total (and the project its won
            // stamp) right away; an open deal totals itself when it finalizes.
            if ($deal->state === DealState::Won) {
                $total = $deal->unitItems()->active()
                    ->where('state', DealState::Won->value)
                    ->get()
                    ->reduce(fn (string $sum, DealItem $i) => Money::add($sum, (string) $i->agreed_price), '0.00');

                $deal->update(['total_price' => $total]);
                $deal->clientProject->restampFromWonDeals();
            }

            return $deal->fresh();
        });
    }
}
