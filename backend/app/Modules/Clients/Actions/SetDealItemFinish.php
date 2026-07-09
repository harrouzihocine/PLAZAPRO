<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Enums\FinishType;

/**
 * Switch the finish (semi-fini / fini) the client committed to on ONE open
 * apartment of an open deal. Only meaningful while the item is still open —
 * once won, the agreed price is the contract and the finish is frozen with it.
 * The shortlist row mirrors the change so the proposal history stays coherent.
 */
class SetDealItemFinish
{
    public function handle(DealItem $item, FinishType $finish): DealItem
    {
        abort_if($item->unit_id === null, 422, 'Only an apartment can switch finish — boxes have none.');
        abort_unless($item->isActive() && $item->state === DealState::Open, 422, 'This apartment is already closed on the deal.');
        abort_unless($item->deal->state === DealState::Open, 422, 'This deal is closed.');

        $unit = $item->unit;
        abort_if(
            $unit->priceFor($finish) === null,
            422,
            "Unit {$unit->reference} has no {$finish->value} price.",
        );

        $item->update(['finish_type' => $finish->value]);

        ShortlistItem::query()->active()
            ->where('client_project_id', $item->deal->client_project_id)
            ->where('shortlistable_type', 'unit')
            ->where('shortlistable_id', $item->unit_id)
            ->update(['finish_type' => $finish->value]);

        return $item;
    }
}
