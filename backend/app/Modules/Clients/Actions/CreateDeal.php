<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Actions\ReserveUnit;
use App\Modules\Inventory\Enums\FinishType;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Actions\ClosePendingNextActions;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Open a deal on a client project from the properties the client is interested
 * in. A project may carry SEVERAL deals at once — the client can commit to one
 * apartment per completed in-site visit, each on its own deal (double-reserving
 * a unit is impossible anyway: ReserveUnit refuses a non-available unit).
 * Workflow rules enforced here:
 *  - a deal comes from an interaction log (visit_id / call_id provenance on this
 *    project); creating one with no log requires the deals.direct permission;
 *  - a unit the client already rejected cannot enter the deal; a unit not yet
 *    shortlisted is shortlisted on the fly (added during the log itself);
 *  - every unit gets a NO-EXPIRY interest hold (only closing the deal
 *    releases or converts it) and its boxes ride with it: a box already linked
 *    to the apartment, or an unlinked one that gets linked here (box_linked,
 *    reverted if the apartment is lost). A box linked to ANOTHER apartment is
 *    refused;
 *  - the deal opening supersedes any pending next-action plan — the project
 *    falls back to the log-a-call CTA (the logs stay open while the deal lives;
 *    an in-site visit scheduled for another apartment stays completable on its
 *    own row).
 */
class CreateDeal
{
    public function __construct(
        private ReserveUnit $reserveUnit,
        private ClosePendingNextActions $closePendingNextActions,
    ) {}

    /**
     * @param  array{visit_id?: int|null, call_id?: int|null, notes?: string|null, units: list<array{unit_id: int, box_ids?: list<int>, finish_type?: string|null}>}  $data
     */
    public function handle(ClientProject $project, array $data, User $actor): Deal
    {
        abort_unless($project->isActive(), 422, 'This project is not active.');
        abort_if($project->isFrozen(), 422, 'This project is frozen — unfreeze it before opening a deal.');

        // Provenance: the deal must come from an interaction log (visit or call)
        // on this project, unless the actor holds the direct-deal permission.
        $visit = null;
        $call = null;
        if (! empty($data['visit_id'])) {
            $visit = Visit::query()->whereKey($data['visit_id'])->first();
            abort_unless(
                $visit !== null && (int) $visit->client_project_id === (int) $project->id,
                422,
                'The deal must come from a visit log on this project.',
            );
        } elseif (! empty($data['call_id'])) {
            $call = Call::query()->whereKey($data['call_id'])->first();
            abort_unless(
                $call !== null && (int) $call->client_project_id === (int) $project->id,
                422,
                'The deal must come from a call log on this project.',
            );
        } else {
            abort_unless(
                $actor->can('deals.direct'),
                403,
                'A deal must come from an interaction log. You are not allowed to create one directly.',
            );
        }

        return DB::transaction(function () use ($project, $data, $actor, $visit, $call) {
            $deal = Deal::create([
                'client_project_id' => $project->id,
                'visit_id' => $visit?->id,
                'call_id' => $call?->id,
                'state' => DealState::Open->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($data['units'] as $entry) {
                $unit = Unit::query()->findOrFail((int) $entry['unit_id']);

                $this->assertClientWantsUnit($project, $unit);

                // No-expiry hold: locks the row, refuses a non-available unit,
                // flips it to interested and records the hold against this project.
                $this->reserveUnit->handle(
                    $unit,
                    ['client_project_id' => $project->id, 'no_expiry' => true],
                    $actor,
                );

                $unitItem = DealItem::create([
                    'deal_id' => $deal->id,
                    'unit_id' => $unit->id,
                    // The finish the client commits to — explicit choice, else
                    // the shortlist proposal, else the unit's default.
                    'finish_type' => $this->finishFor($project, $unit, $entry['finish_type'] ?? null),
                ]);

                $this->attachBoxes($unitItem, $unit, array_map(intval(...), $entry['box_ids'] ?? []));
            }

            // Opening the deal supersedes whatever was planned next: the pending
            // plan is retired so the project falls back to its default CTA — log a
            // call (the logs stay open while the deal lives; freezing the project
            // is what silences the CTA). Any in-site visit still scheduled for
            // another apartment stays completable on its own (its "Complete"
            // button rides on the visit row, not on this pending plan), so each
            // can still conclude into its own deal.
            $this->closePendingNextActions->handle($project, 'Superseded by the deal');

            // The properties are committed — the project sits at the deal
            // step (a project already won by an earlier deal stays won).
            if (! in_array($project->stage, [ClientProjectStage::Deal, ClientProjectStage::Won], true)) {
                $project->update(['stage' => ClientProjectStage::Deal->value]);
            }

            return $deal;
        });
    }

    /**
     * The finish this apartment enters the deal at: the explicit choice
     * (guarded against finishes the unit doesn't offer), else the shortlist
     * proposal when still offered, else the unit's default (semi-fini first).
     * The shortlist row mirrors the outcome so proposal and commitment agree.
     */
    private function finishFor(ClientProject $project, Unit $unit, ?string $explicit): string
    {
        $shortlisted = ShortlistItem::query()->active()
            ->where('client_project_id', $project->id)
            ->where('shortlistable_type', 'unit')
            ->where('shortlistable_id', $unit->id)
            ->first();

        if ($explicit !== null) {
            $finish = FinishType::from($explicit);
            abort_if(
                $unit->priceFor($finish) === null,
                422,
                "Unit {$unit->reference} has no {$finish->value} price.",
            );
        } elseif ($shortlisted?->finish_type !== null && $unit->priceFor($shortlisted->finish_type) !== null) {
            $finish = $shortlisted->finish_type;
        } else {
            $finish = $unit->defaultFinish();
        }

        $shortlisted?->update(['finish_type' => $finish->value]);

        return $finish->value;
    }

    /**
     * A unit the client already passed on cannot enter the deal. A unit not on
     * the shortlist yet is shortlisted on the fly (picked during the log), so
     * the property journey stays consistent.
     */
    private function assertClientWantsUnit(ClientProject $project, Unit $unit): void
    {
        $item = ShortlistItem::query()->active()
            ->where('client_project_id', $project->id)
            ->where('shortlistable_type', 'unit')
            ->where('shortlistable_id', $unit->id)
            ->first();

        if ($item === null) {
            ShortlistItem::create([
                'client_project_id' => $project->id,
                'shortlistable_type' => 'unit',
                'shortlistable_id' => $unit->id,
                'state' => ShortlistState::Shortlisted->value,
            ]);

            return;
        }

        abort_if(
            in_array($item->state, [ShortlistState::VisitedNotInterested, ShortlistState::Lost, ShortlistState::Won], true),
            422,
            "The client already passed on {$unit->reference} — it cannot enter the deal.",
        );
    }

    /**
     * Attach the chosen boxes with the apartment (they go Interested with it).
     * Only a box already linked to
     * THIS apartment or not linked to any apartment may ride along; an unlinked
     * box is linked here (box_linked → reverted if the apartment is lost).
     *
     * @param  list<int>  $boxIds
     */
    private function attachBoxes(DealItem $unitItem, Unit $unit, array $boxIds): void
    {
        foreach (array_unique($boxIds) as $boxId) {
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
                'sale_status' => SaleStatus::Interested->value,
                'unit_id' => $unit->id,
            ]);

            DealItem::create([
                'deal_id' => $unitItem->deal_id,
                'box_id' => $box->id,
                'parent_item_id' => $unitItem->id,
                'box_linked' => $linkedHere,
            ]);
        }
    }
}
