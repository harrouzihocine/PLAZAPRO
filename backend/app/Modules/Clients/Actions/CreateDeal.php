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
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Open THE deal on a client project from the properties the client is interested
 * in. Workflow rules enforced here:
 *  - one ACTIVE (reserved) deal per project — no deal stacking;
 *  - a deal comes from a visit log (visit_id provenance on this project); creating
 *    one with no visit requires the deals.direct permission;
 *  - only shortlisted properties the client has not rejected can enter the deal
 *    (for a permitted direct deal, the unit is shortlisted on the fly);
 *  - every unit is auto-reserved (48h hold via ReserveUnit) and each requested box
 *    is allocated from the unit's location (available and not taken) and reserved.
 */
class CreateDeal
{
    public function __construct(private ReserveUnit $reserveUnit) {}

    /**
     * @param  array{visit_id?: int|null, notes?: string|null, units: list<array{unit_id: int, box_count?: int}>}  $data
     */
    public function handle(ClientProject $project, array $data, User $actor): Deal
    {
        abort_unless($project->isActive(), 422, 'This project is not active.');
        abort_if($project->stage === ClientProjectStage::Won, 422, 'This project is already won.');
        abort_if(
            $project->deals()->active()->where('state', DealState::Reserved->value)->exists(),
            422,
            'This project already has an active deal. Close it (won / lost) first.',
        );

        // Provenance: the deal must come from a visit log on this project, unless
        // the actor holds the direct-deal permission.
        $visit = null;
        if (! empty($data['visit_id'])) {
            $visit = Visit::query()->whereKey($data['visit_id'])->first();
            abort_unless(
                $visit !== null && (int) $visit->client_project_id === (int) $project->id,
                422,
                'The deal must come from a visit log on this project.',
            );
        } else {
            abort_unless(
                $actor->can('deals.direct'),
                403,
                'A deal must come from a visit log. You are not allowed to create one directly.',
            );
        }

        return DB::transaction(function () use ($project, $data, $actor, $visit) {
            $deal = Deal::create([
                'client_project_id' => $project->id,
                'visit_id' => $visit?->id,
                'state' => DealState::Reserved->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($data['units'] as $entry) {
                $unit = Unit::query()->findOrFail((int) $entry['unit_id']);

                $this->assertClientWantsUnit($project, $unit, $actor);

                // 48h hold: locks the row, refuses a non-available unit, flips it
                // to reserved and records the hold against this project.
                $this->reserveUnit->handle($unit, ['client_project_id' => $project->id], $actor);

                DealItem::create(['deal_id' => $deal->id, 'unit_id' => $unit->id]);

                $this->allocateBoxes($deal, $unit, (int) ($entry['box_count'] ?? 0));
            }

            // The properties are committed — the project sits at the reserved step.
            if ($project->stage !== ClientProjectStage::Reserved) {
                $project->update(['stage' => ClientProjectStage::Reserved->value]);
            }

            return $deal;
        });
    }

    /**
     * A unit enters the deal only when the client shortlisted it and has not
     * rejected/closed it. A permitted direct deal shortlists the unit on the fly
     * so the property journey stays consistent.
     */
    private function assertClientWantsUnit(ClientProject $project, Unit $unit, User $actor): void
    {
        $item = ShortlistItem::query()->active()
            ->where('client_project_id', $project->id)
            ->where('shortlistable_type', 'unit')
            ->where('shortlistable_id', $unit->id)
            ->first();

        if ($item === null) {
            abort_unless(
                $actor->can('deals.direct'),
                422,
                'Only a shortlisted property the client is interested in can enter the deal.',
            );

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

    /** Allocate N available boxes from the unit's location and reserve them. */
    private function allocateBoxes(Deal $deal, Unit $unit, int $count): void
    {
        if ($count < 1) {
            return;
        }

        $boxes = Box::query()->active()
            ->where('location_id', $unit->location_id)
            ->where('sale_status', SaleStatus::Available->value)
            ->orderBy('reference')
            ->lockForUpdate()
            ->limit($count)
            ->get();

        abort_unless(
            $boxes->count() === $count,
            422,
            "Only {$boxes->count()} box(es) are still available in this project.",
        );

        foreach ($boxes as $box) {
            $box->update(['sale_status' => SaleStatus::Reserved->value]);
            DealItem::create(['deal_id' => $deal->id, 'box_id' => $box->id]);
        }
    }
}
