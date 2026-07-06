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
use App\Modules\Inventory\Events\UnitSold;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Support\Money;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Close ONE apartment on the deal — each apartment is tracked alone:
 *  - won  → an agreed price is required (it covers the apartment AND its boxes);
 *           the hold converts, the unit and its boxes are sold, payments for
 *           this apartment can start;
 *  - lost → the hold releases, the unit and its boxes return to available
 *           inventory, and a box THIS deal linked to the apartment is unlinked.
 *
 * When the last reserved apartment resolves, the deal itself closes:
 *  - at least one won → deal won (total = the agreed prices), project won;
 *  - all lost → deal lost, resolved like before: `reopen` steps the project
 *    back to negotiating (a fresh deal can start) or `archive` closes it.
 */
class CloseDealUnit
{
    public function __construct(private ArchiveClientProject $archive) {}

    /**
     * @param  array{sale?: list<int>, insite?: list<int>, other?: list<int>}|null  $credits
     *                                                                                        who gets credit for the sale (only used on a win)
     */
    public function handle(
        DealItem $item,
        string $outcome,
        ?string $agreedPrice = null,
        ?string $resolution = null,
        ?string $note = null,
        ?array $credits = null,
    ): Deal {
        $deal = $item->deal;

        abort_unless($deal->isActive() && ! $deal->state->isClosed(), 422, 'This deal is not open.');
        abort_unless($item->isActive() && $item->unit_id !== null, 422, 'This is not an apartment on the deal.');
        abort_if($item->state->isClosed(), 422, 'This apartment is already resolved.');

        $deal = DB::transaction(function () use ($deal, $item, $outcome, $agreedPrice, $resolution, $note, $credits) {
            // Serialize on the unit row so two concurrent closes on the same unit
            // (via different deals, or a double-clicked "Mark won") can never both
            // sell it — the same lock ReserveUnit takes. Holding the lock, re-read
            // the deal and this apartment and re-assert they are still open before
            // acting, since the pre-transaction guards ran on a stale snapshot.
            Unit::whereKey($item->unit_id)->lockForUpdate()->firstOrFail();
            $item->refresh();
            $freshDeal = $deal->fresh();
            abort_unless($freshDeal->isActive() && ! $freshDeal->state->isClosed(), 422, 'This deal is not open.');
            abort_if($item->state->isClosed(), 422, 'This apartment is already resolved.');

            $outcome === 'won'
                ? $this->win($item, $agreedPrice, $credits)
                : $this->lose($item);

            $this->finalizeWhenResolved($deal, $resolution ?? 'reopen', $note);

            return $deal->fresh();
        });

        // Celebration + all-users bell (after commit, so it never fires on a
        // rolled-back sale). The status-change broadcast is fired by the Unit
        // model hook automatically for every transition above.
        if ($outcome === 'won') {
            $item->loadMissing([
                'unit.location.type', 'unit.location.wilaya', 'unit.location.commune',
                'unit.floor', 'unit.roomNumber', 'deal.creator',
            ]);
            $names = $this->creditedNames($credits);
            $unit = $item->unit;
            // Broadcast the UNIT (+ its spec) and the credited agents to everyone;
            // the client's identity is deliberately left out (it goes to all
            // staff, and the name-hiding / anti-poaching rules apply to clients).
            UnitSold::dispatch(
                (int) $item->unit_id,
                (string) $unit?->reference,
                $unit?->location?->name,
                null,
                $item->deal->creator?->name,
                $agreedPrice,
                $names['sale'],
                $names['insite'],
                $names['other'],
                [
                    'type' => $unit?->location?->type?->label,
                    'room_number' => $unit?->roomNumber?->label,
                    'floor' => $unit?->floor?->label,
                    'area_sqm' => $unit?->area_sqm !== null ? (string) $unit->area_sqm : null,
                    'address' => $this->composeAddress($unit),
                ],
            );
        }

        return $deal;
    }

    private function win(DealItem $item, ?string $agreedPrice, ?array $credits = null): void
    {
        abort_if(
            $agreedPrice === null,
            422,
            'An agreed price is required to win an apartment (it covers its boxes too).',
        );

        $item->load(['unit', 'boxItems' => fn ($q) => $q->active(), 'boxItems.box']);
        $unit = $item->unit;

        // The unit row is locked by the caller's transaction: refuse the win if a
        // concurrent close has just sold it (belt-and-braces against a double-sale).
        abort_if($unit->sale_status === SaleStatus::Sold, 422, 'This unit has just been sold.');

        // On Hold locks the sale to its holder: only that project may buy it.
        abort_if(
            $unit->sale_status === SaleStatus::OnHold
                && (int) $unit->onhold_project_id !== (int) $item->deal->client_project_id,
            422,
            'This unit is on hold for another client.',
        );

        $this->activeHold($item)?->update(['hold_status' => HoldStatus::Converted->value]);

        // The sale ends every backup — other projects that reserved this unit
        // lose it (their deals re-resolve; the all-users "sold" bell tells them).
        $this->releaseBackups($item);

        $unit->update([
            'sale_status' => SaleStatus::Sold->value,
            'onhold_expires_at' => null,
            'onhold_project_id' => null,
        ]);
        $this->shortlistItemFor($item)?->update(['state' => ShortlistState::Won->value]);

        foreach ($item->boxItems as $boxItem) {
            $boxItem->box->update(['sale_status' => SaleStatus::Sold->value]);
            $boxItem->update(['state' => DealState::Won->value, 'closed_at' => now()]);
        }

        $item->update([
            'state' => DealState::Won->value,
            'agreed_price' => $agreedPrice,
            'closed_at' => now(),
            'credits' => $this->normalizeCredits($credits),
        ]);
    }

    /** A readable one-line address for the unit's project: street · commune · wilaya. */
    private function composeAddress(?Unit $unit): ?string
    {
        $location = $unit?->location;
        if ($location === null) {
            return null;
        }

        $parts = array_filter([
            $location->address,
            $location->commune?->name,
            $location->wilaya?->name,
        ]);

        return $parts !== [] ? implode(' · ', $parts) : null;
    }

    /** Keep only int id lists per role; null when nobody was credited. */
    private function normalizeCredits(?array $credits): ?array
    {
        if ($credits === null) {
            return null;
        }

        $ints = fn (string $key) => array_values(array_unique(
            array_map('intval', $credits[$key] ?? []),
        ));

        $out = ['sale' => $ints('sale'), 'insite' => $ints('insite'), 'other' => $ints('other')];

        return array_filter($out, fn (array $ids) => $ids !== []) === [] ? null : $out;
    }

    /**
     * Resolve the credited user ids to names, grouped by role, for the sold
     * broadcast.
     *
     * @return array{sale: list<string>, insite: list<string>, other: list<string>}
     */
    private function creditedNames(?array $credits): array
    {
        $empty = ['sale' => [], 'insite' => [], 'other' => []];

        if ($credits === null) {
            return $empty;
        }

        $ids = collect($credits)->flatten()->map(fn ($id) => (int) $id)->unique()->all();

        if ($ids === []) {
            return $empty;
        }

        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id');
        $resolve = fn (string $key) => collect($credits[$key] ?? [])
            ->map(fn ($id) => $names[(int) $id] ?? null)
            ->filter()->values()->all();

        return ['sale' => $resolve('sale'), 'insite' => $resolve('insite'), 'other' => $resolve('other')];
    }

    private function lose(DealItem $item): void
    {
        $item->load(['unit', 'boxItems' => fn ($q) => $q->active(), 'boxItems.box']);

        $this->activeHold($item)?->update(['hold_status' => HoldStatus::Released->value]);

        $this->shortlistItemFor($item)?->update(['state' => ShortlistState::Lost->value]);

        foreach ($item->boxItems as $boxItem) {
            $box = $boxItem->box;
            $box->update([
                'sale_status' => $box->sale_status === SaleStatus::Reserved
                    ? SaleStatus::Available->value
                    : $box->sale_status,
                // A link created by this deal is reverted with it.
                'unit_id' => $boxItem->box_linked ? null : $box->unit_id,
            ]);
            $boxItem->update(['state' => DealState::Lost->value, 'closed_at' => now()]);
        }

        $item->update(['state' => DealState::Lost->value, 'closed_at' => now()]);

        // Back to the market: reserved if other projects still hold it, else
        // available. But a backup losing must NOT lift a lock held by a DIFFERENT
        // project — leave that unit On Hold for its holder.
        $unit = $item->unit;
        $heldByAnother = $unit->sale_status === SaleStatus::OnHold
            && (int) $unit->onhold_project_id !== (int) $item->deal->client_project_id;
        if (! $heldByAnother) {
            $unit->revertToMarket();
        }
    }

    /**
     * Every OTHER open deal that reserved this same unit loses it when it sells —
     * release each backup project's hold, free the boxes it linked, close its
     * apartment item lost and let that deal re-resolve (steps its project back
     * into the pipeline). Leaves the unit's own status to win() (→ sold).
     */
    private function releaseBackups(DealItem $wonItem): void
    {
        $backups = DealItem::query()->active()
            ->where('unit_id', $wonItem->unit_id)
            ->where('state', DealState::Reserved->value)
            ->where('deal_id', '!=', $wonItem->deal_id)
            ->with(['deal', 'boxItems' => fn ($q) => $q->active(), 'boxItems.box'])
            ->get();

        foreach ($backups as $backup) {
            $this->activeHold($backup)?->update(['hold_status' => HoldStatus::Released->value]);
            $this->shortlistItemFor($backup)?->update(['state' => ShortlistState::Lost->value]);

            foreach ($backup->boxItems as $boxItem) {
                $box = $boxItem->box;
                $box->update([
                    'sale_status' => $box->sale_status === SaleStatus::Reserved
                        ? SaleStatus::Available->value
                        : $box->sale_status,
                    'unit_id' => $boxItem->box_linked ? null : $box->unit_id,
                ]);
                $boxItem->update(['state' => DealState::Lost->value, 'closed_at' => now()]);
            }

            $backup->update(['state' => DealState::Lost->value, 'closed_at' => now()]);

            $this->finalizeWhenResolved($backup->deal, 'reopen', 'Unit sold to another client');
        }
    }

    /** Once every apartment is resolved, the deal itself closes. */
    private function finalizeWhenResolved(Deal $deal, string $resolution, ?string $note): void
    {
        $stillOpen = $deal->unitItems()->active()
            ->where('state', DealState::Reserved->value)
            ->exists();

        if ($stillOpen) {
            return;
        }

        $wonItems = $deal->unitItems()->active()
            ->where('state', DealState::Won->value)
            ->get();

        if ($wonItems->isNotEmpty()) {
            $total = $wonItems->reduce(
                fn (string $sum, DealItem $item) => Money::add($sum, (string) $item->agreed_price),
                '0.00',
            );

            $deal->update(['state' => DealState::Won->value, 'total_price' => $total]);

            // The project is won: stamped with the first sold unit and the
            // aggregate agreed total across ALL its deals (a project may carry
            // several) — payments (per apartment) take over from here.
            $deal->clientProject->restampFromWonDeals();

            return;
        }

        // The client passed on everything.
        $deal->update(['state' => DealState::Lost->value]);

        $project = $deal->clientProject;

        // Another deal still carries the project (open or won): this one simply
        // closes — there is nothing to resolve, the work continues over there.
        // (Also shields an API-sent `archive` from aborting the whole close.)
        $otherEngaged = $project->deals()->active()
            ->whereKeyNot($deal->id)
            ->whereIn('state', [DealState::Reserved->value, DealState::Won->value])
            ->exists();
        if ($otherEngaged) {
            return;
        }

        if ($resolution === 'archive') {
            // Keep any recorded payments as history (the note explains the
            // refund); the reason flags it for the oversight monitor.
            $keepPayments = $project->versements()->active()->exists();
            $reason = trim('Deal lost'.($note !== null && $note !== '' ? ' — '.$note : ''));
            $this->archive->handle($project, $reason, $keepPayments);

            return;
        }

        // reopen: step the project back so a fresh deal cycle can start (the
        // lost deal is kept as history) — the log workflow unfreezes here.
        if ($project->isActive() && $project->stage === ClientProjectStage::Reserved) {
            $project->update(['stage' => ClientProjectStage::Negotiating->value]);
        }
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
