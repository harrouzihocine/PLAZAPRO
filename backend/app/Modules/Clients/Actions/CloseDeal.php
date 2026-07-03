<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Close the project's active deal:
 *  - won  → the client buys everything reserved on it: holds convert, units and
 *           boxes are sold, the project advances to `won` with the agreed price
 *           (defaulted client-side from the reserved property prices) — payments
 *           (the financer) take over from there;
 *  - lost → the client passes: holds release, units and boxes return to available
 *           inventory, and the project steps back to `negotiating` so the follow-up
 *           can continue (or the project be archived / shifted to desire).
 */
class CloseDeal
{
    public function handle(Deal $deal, string $outcome, ?string $totalPrice = null): Deal
    {
        abort_unless($deal->isActive(), 422, 'This deal is not active.');
        abort_if($deal->state->isClosed(), 422, 'This deal is already closed.');

        return DB::transaction(function () use ($deal, $outcome, $totalPrice) {
            return $outcome === 'won'
                ? $this->win($deal, $totalPrice)
                : $this->lose($deal);
        });
    }

    private function win(Deal $deal, ?string $totalPrice): Deal
    {
        abort_if($totalPrice === null, 422, 'An agreed total price is required to win the deal.');

        $unitItems = $deal->unitItems()->active()->with('unit')->get();
        abort_if($unitItems->isEmpty(), 422, 'The deal has no reserved unit to sell.');

        $project = $deal->clientProject;

        foreach ($unitItems as $item) {
            $this->activeHold($deal, $item->unit_id)?->update(['hold_status' => HoldStatus::Converted->value]);
            $item->unit->update(['sale_status' => SaleStatus::Sold->value]);
            $this->shortlistItemFor($deal, $item->unit_id)?->update(['state' => ShortlistState::Won->value]);
        }

        foreach ($deal->boxItems()->active()->with('box')->get() as $item) {
            $item->box->update(['sale_status' => SaleStatus::Sold->value]);
        }

        $deal->update(['state' => DealState::Won->value, 'total_price' => $totalPrice]);

        // The project is won: stamped with the (first) sold unit and the agreed
        // price — this is what unlocks the payment schedule (Phase 4).
        $project->update([
            'stage' => ClientProjectStage::Won->value,
            'unit_id' => $unitItems->first()->unit_id,
            'total_price' => $totalPrice,
        ]);

        return $deal->fresh();
    }

    private function lose(Deal $deal): Deal
    {
        $project = $deal->clientProject;

        foreach ($deal->unitItems()->active()->with('unit')->get() as $item) {
            $this->activeHold($deal, $item->unit_id)?->update(['hold_status' => HoldStatus::Released->value]);

            if ($item->unit->sale_status === SaleStatus::Reserved) {
                $item->unit->update(['sale_status' => SaleStatus::Available->value]);
            }

            $this->shortlistItemFor($deal, $item->unit_id)?->update(['state' => ShortlistState::Lost->value]);
        }

        foreach ($deal->boxItems()->active()->with('box')->get() as $item) {
            if ($item->box->sale_status === SaleStatus::Reserved) {
                $item->box->update(['sale_status' => SaleStatus::Available->value]);
            }
        }

        $deal->update(['state' => DealState::Lost->value]);

        // Step the project back so the follow-up continues (archive / desire are
        // separate, explicit moves).
        if ($project->isActive() && $project->stage === ClientProjectStage::Reserved) {
            $project->update(['stage' => ClientProjectStage::Negotiating->value]);
        }

        return $deal->fresh();
    }

    private function activeHold(Deal $deal, int $unitId): ?Reservation
    {
        return Reservation::query()
            ->where('unit_id', $unitId)
            ->where('client_project_id', $deal->client_project_id)
            ->where('hold_status', HoldStatus::Active->value)
            ->latest('id')
            ->first();
    }

    private function shortlistItemFor(Deal $deal, int $unitId): ?ShortlistItem
    {
        return ShortlistItem::query()->active()
            ->where('client_project_id', $deal->client_project_id)
            ->where('shortlistable_type', 'unit')
            ->where('shortlistable_id', $unitId)
            ->first();
    }
}
