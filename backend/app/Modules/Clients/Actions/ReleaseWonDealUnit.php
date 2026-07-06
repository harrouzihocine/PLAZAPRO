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
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Payments\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Release a WON apartment — the win is reversible even after the deal closed
 * (a sale that falls through). The reversal mirrors the win: the converted
 * hold releases, the unit and its boxes return to available inventory (a box
 * linked by this deal unlinks) and the apartment closes lost on the deal.
 *
 * Recorded payments are deliberately NOT touched: they stay visible as history
 * (each can be refunded on its own — RefundVersement).
 *
 * When nothing won remains anywhere on the project, it resolves like a lost
 * deal always has: `reopen` steps it back into the pipeline (the log workflow
 * resumes) or `archive` closes it — payments kept as history either way.
 */
class ReleaseWonDealUnit
{
    public function __construct(private ArchiveClientProject $archive) {}

    public function handle(DealItem $item, string $resolution = 'reopen', ?string $note = null): Deal
    {
        $deal = $item->deal;

        abort_unless($deal->isActive(), 422, 'This deal is not active.');
        abort_unless($item->isActive() && $item->unit_id !== null, 422, 'This is not an apartment on the deal.');
        abort_unless($item->state === DealState::Won, 422, 'Only a won apartment can be released.');

        return DB::transaction(function () use ($deal, $item, $resolution, $note) {
            $item->load(['unit', 'boxItems' => fn ($q) => $q->active(), 'boxItems.box']);

            // Reverse the win: the converted hold releases and the properties
            // go back on the market — interested if any other project still holds
            // it (a backup that survived), else available.
            $this->convertedHold($item)?->update(['hold_status' => HoldStatus::Released->value]);

            if ($item->unit->sale_status === SaleStatus::Sold) {
                $item->unit->update([
                    'reserved_expires_at' => null,
                    'reserved_project_id' => null,
                    'sale_status' => $item->unit->hasActiveHold()
                        ? SaleStatus::Interested->value
                        : SaleStatus::Available->value,
                ]);
            }

            $this->shortlistItemFor($item)?->update(['state' => ShortlistState::Lost->value]);

            foreach ($item->boxItems as $boxItem) {
                $box = $boxItem->box;
                $box->update([
                    'sale_status' => $box->sale_status === SaleStatus::Sold
                        ? SaleStatus::Available->value
                        : $box->sale_status,
                    // A link created by this deal is reverted with it.
                    'unit_id' => $boxItem->box_linked ? null : $box->unit_id,
                ]);
                $boxItem->update(['state' => DealState::Lost->value, 'closed_at' => now()]);
            }

            $item->update(['state' => DealState::Lost->value, 'closed_at' => now()]);

            $this->recomputeDeal($deal);
            $this->resolveProject($deal, $resolution, $note);

            return $deal->fresh();
        });
    }

    /** The deal's state re-derives from what is left on it after the release. */
    private function recomputeDeal(Deal $deal): void
    {
        $won = $deal->unitItems()->active()
            ->where('state', DealState::Won->value)
            ->get();

        if ($won->isNotEmpty()) {
            $deal->update(['total_price' => $won->reduce(
                fn (string $sum, DealItem $i) => Money::add($sum, (string) $i->agreed_price),
                '0.00',
            )]);

            return;
        }

        $stillReserved = $deal->unitItems()->active()
            ->where('state', DealState::Open->value)
            ->exists();

        $deal->update([
            'state' => $stillReserved ? DealState::Open->value : DealState::Lost->value,
            'total_price' => null,
        ]);
    }

    /**
     * The project stays won while ANY of its deals still holds a won apartment
     * (the stamp re-derives). Otherwise the caller's resolution applies:
     * archive (payments kept as history) or reopen into the pipeline.
     */
    private function resolveProject(Deal $deal, string $resolution, ?string $note): void
    {
        $project = $deal->clientProject;

        if ($project->restampFromWonDeals()) {
            return;
        }

        // Another deal is still open: the project keeps living on it — there
        // is nothing to resolve here, it just steps back to the deal stage
        // (an API-sent resolution is moot and deliberately ignored).
        $anotherOpen = $project->deals()->active()
            ->where('state', DealState::Open->value)
            ->exists();
        if ($anotherOpen) {
            if ($project->isActive()) {
                $project->update([
                    'stage' => ClientProjectStage::Deal->value,
                    'unit_id' => null,
                    'total_price' => null,
                ]);
            }

            return;
        }

        if ($resolution === 'archive') {
            $reason = trim('Won apartment released'.($note !== null && $note !== '' ? ' — '.$note : ''));
            $this->archive->handle($project, $reason, true);

            return;
        }

        // reopen: nothing won remains — clear the stamp and step back to
        // negotiating so the logs resume.
        if ($project->isActive()) {
            $project->update([
                'stage' => ClientProjectStage::Negotiating->value,
                'unit_id' => null,
                'total_price' => null,
            ]);
        }
    }

    private function convertedHold(DealItem $item): ?Reservation
    {
        return Reservation::query()
            ->where('unit_id', $item->unit_id)
            ->where('client_project_id', $item->deal->client_project_id)
            ->where('hold_status', HoldStatus::Converted->value)
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
