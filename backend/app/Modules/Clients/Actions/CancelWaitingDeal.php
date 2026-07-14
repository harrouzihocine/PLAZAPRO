<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\ReservedReleased;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Undo a deal that was opened by a log now being corrected — allowed only while
 * the deal is "just waiting" (open, nothing won/lost, no payment; see
 * Deal::isJustWaiting). Unlike closing a deal LOST (a client decision, kept in
 * lost-deal analytics), this is a mistake unwind: the deal and its items are
 * CANCELLED (kept as history, out of the active lists), each held apartment and
 * its boxes go back to the market, and shortlisted apartments fall back to plain
 * candidates (the client never rejected them — the log was wrong).
 *
 * The unit-release steps mirror CloseDealUnit::lose exactly (hold released, boxes
 * freed / a deal-linked box unlinked, the unit returned to market unless it is
 * held by another project), so inventory stays consistent either way.
 */
class CancelWaitingDeal
{
    public function handle(Deal $deal, string $reason): Deal
    {
        abort_unless($deal->isActive() && $deal->isJustWaiting(), 422, 'This deal has moved on — it can no longer be cancelled by editing the log.');

        // Units whose reserved deposit-lock this cancel lifted — the queue is
        // promoted after commit (like CloseDealUnit). A just-waiting deal holds
        // no deposit of its own, but a stale explicit hold is handled all the same.
        $releasedUnits = [];

        DB::transaction(function () use ($deal, $reason, &$releasedUnits) {
            $items = $deal->unitItems()->active()
                ->where('state', DealState::Open->value)
                ->get()
                ->each->setRelation('deal', $deal);

            foreach ($items as $item) {
                // Serialize on the unit row (the lock ReserveUnit / CloseDealUnit take).
                Unit::whereKey($item->unit_id)->lockForUpdate()->firstOrFail();
                $item->load(['unit', 'boxItems' => fn ($q) => $q->active(), 'boxItems.box']);

                $this->activeHold($item)?->update(['hold_status' => HoldStatus::Released->value]);

                // Not a rejection — the property stays a candidate on the shortlist.
                $this->shortlistItemFor($item)?->update(['state' => ShortlistState::Shortlisted->value]);

                foreach ($item->boxItems as $boxItem) {
                    $box = $boxItem->box;
                    $box->update([
                        'sale_status' => $box->sale_status === SaleStatus::Interested
                            ? SaleStatus::Available->value
                            : $box->sale_status,
                        // A link this deal created is reverted with it.
                        'unit_id' => $boxItem->box_linked ? null : $box->unit_id,
                    ]);
                    $boxItem->cancel($reason);
                }

                // A backup on a unit reserved by ANOTHER project must not touch
                // that project's lock — leave it Reserved for its holder.
                $unit = $item->unit;
                $heldByAnother = $unit->sale_status === SaleStatus::Reserved
                    && (int) $unit->reserved_project_id !== (int) $deal->client_project_id;

                if (! $heldByAnother) {
                    $liftsOwnLock = $unit->sale_status === SaleStatus::Reserved
                        && (int) $unit->reserved_project_id === (int) $deal->client_project_id;
                    $unit->revertToMarket();
                    if ($liftsOwnLock) {
                        $releasedUnits[$unit->id] = $unit;
                    }
                }

                $item->cancel($reason);
            }

            $deal->cancel($reason);

            // Step the project back so a fresh cycle can start — unless another
            // deal still engages it (open or won).
            $project = $deal->clientProject;
            $otherEngaged = $project->deals()->active()
                ->whereKeyNot($deal->id)
                ->whereIn('state', [DealState::Open->value, DealState::Won->value])
                ->exists();

            if (! $otherEngaged && $project->isActive() && $project->stage === ClientProjectStage::Deal) {
                $project->update(['stage' => ClientProjectStage::Negotiating->value]);
            }
        });

        // Promote the reservation queue for any unit whose lock we lifted.
        DB::afterCommit(function () use ($deal, $releasedUnits): void {
            foreach ($releasedUnits as $unit) {
                ReservedReleased::dispatch($unit, (int) $deal->client_project_id);
            }
        });

        return $deal->fresh();
    }

    private function activeHold(DealItem $item): ?Reservation
    {
        return Reservation::query()
            ->where('unit_id', $item->unit_id)
            ->where('client_project_id', $item->deal->client_project_id)
            ->where('hold_status', HoldStatus::Active->value)
            ->latest('id')
            ->first();
    }

    private function shortlistItemFor(DealItem $item): ?ShortlistItem
    {
        return ShortlistItem::query()->active()
            ->where('client_project_id', $item->deal->client_project_id)
            ->where('shortlistable_type', 'unit')
            ->where('shortlistable_id', $item->unit_id)
            ->first();
    }
}
