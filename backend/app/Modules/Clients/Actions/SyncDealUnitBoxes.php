<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;
use Illuminate\Support\Facades\DB;

/**
 * Re-set the boxes riding with ONE apartment on an open deal: boxes dropped
 * from the list are released (and unlinked when this deal linked them), new
 * ones are reserved. Only a box already linked to this apartment or not linked
 * to any apartment can be picked — never one belonging to another apartment or
 * taken by someone else.
 */
class SyncDealUnitBoxes
{
    /**
     * @param  list<int>  $boxIds
     */
    public function handle(DealItem $unitItem, array $boxIds): Deal
    {
        $deal = $unitItem->deal;

        abort_unless($deal->isActive() && ! $deal->state->isClosed(), 422, 'This deal is not open.');
        abort_unless($unitItem->isActive() && $unitItem->unit_id !== null, 422, 'This is not an apartment on the deal.');
        abort_if($unitItem->state->isClosed(), 422, 'This apartment is already resolved — its boxes cannot change.');

        return DB::transaction(function () use ($deal, $unitItem, $boxIds) {
            $unit = $unitItem->unit;
            $wanted = array_unique(array_map(intval(...), $boxIds));
            $current = $unitItem->boxItems()->active()->with('box')->get();

            // Release the boxes dropped from the apartment.
            foreach ($current as $item) {
                if (in_array((int) $item->box_id, $wanted, true)) {
                    continue;
                }

                $box = $item->box;
                $box->update([
                    'sale_status' => $box->sale_status === SaleStatus::Reserved
                        ? SaleStatus::Available->value
                        : $box->sale_status,
                    // A link created by this deal is reverted with it.
                    'unit_id' => $item->box_linked ? null : $box->unit_id,
                ]);
                $item->cancel('Removed from deal');
            }

            // Reserve the newly added ones (linked here when not linked yet).
            $kept = $current->pluck('box_id')->map(fn ($id) => (int) $id)->all();
            foreach ($wanted as $boxId) {
                if (in_array($boxId, $kept, true)) {
                    continue;
                }

                $box = Box::query()->active()->whereKey($boxId)->lockForUpdate()->first();
                abort_if($box === null, 422, 'A selected box does not exist.');
                abort_unless(
                    (int) $box->location_id === (int) $unit->location_id,
                    422,
                    "Box {$box->reference} belongs to another location.",
                );
                abort_unless(
                    $box->unit_id === null || (int) $box->unit_id === (int) $unit->id,
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
                    'sale_status' => SaleStatus::Reserved->value,
                    'unit_id' => $unit->id,
                ]);

                DealItem::create([
                    'deal_id' => $deal->id,
                    'box_id' => $box->id,
                    'parent_item_id' => $unitItem->id,
                    'box_linked' => $linkedHere,
                ]);
            }

            return $deal->fresh();
        });
    }
}
