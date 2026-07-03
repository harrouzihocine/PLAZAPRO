<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;
use Illuminate\Support\Facades\DB;

/**
 * Re-set the boxes reserved on an OPEN deal to the given set: boxes dropped from
 * the list are released back to available, new ones are reserved. Only a box that
 * is available (or already on this deal) can be picked — never one taken by
 * someone else.
 */
class SyncDealBoxes
{
    /**
     * @param  list<int>  $boxIds
     */
    public function handle(Deal $deal, array $boxIds): Deal
    {
        abort_unless($deal->isActive(), 422, 'This deal is not active.');
        abort_if($deal->state->isClosed(), 422, 'A closed deal cannot change its boxes.');

        return DB::transaction(function () use ($deal, $boxIds) {
            $wanted = array_map(intval(...), $boxIds);
            $current = $deal->boxItems()->active()->get();

            // Release the boxes dropped from the deal.
            foreach ($current as $item) {
                if (! in_array((int) $item->box_id, $wanted, true)) {
                    $item->box()->where('sale_status', SaleStatus::Reserved->value)
                        ->update(['sale_status' => SaleStatus::Available->value]);
                    $item->cancel('Removed from deal');
                }
            }

            // Reserve the newly added ones.
            $kept = $current->pluck('box_id')->map(fn ($id) => (int) $id)->all();
            foreach ($wanted as $boxId) {
                if (in_array($boxId, $kept, true)) {
                    continue;
                }

                $box = Box::query()->active()->whereKey($boxId)->lockForUpdate()->first();
                abort_if($box === null, 422, 'A selected box does not exist.');
                abort_unless(
                    $box->sale_status === SaleStatus::Available,
                    422,
                    "Box {$box->reference} is not available.",
                );

                $box->update(['sale_status' => SaleStatus::Reserved->value]);
                DealItem::create(['deal_id' => $deal->id, 'box_id' => $box->id]);
            }

            return $deal->fresh();
        });
    }
}
