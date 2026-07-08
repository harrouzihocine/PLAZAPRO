<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Events\VersementRecorded;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Record an instalment payment and, when it settles a schedule item, allocate it —
 * updating that item's paid_amount/state — all in one transaction. The versement
 * is immutable afterwards: corrections go through CorrectVersement (supersedeWith).
 *
 * A payment on a not-yet-sold unit is a holding deposit: it Reserves the unit
 * for this project (off the market for everyone else, backups aside) until it
 * is sold or the deposit window lapses. Post-sale instalments never re-trigger
 * this.
 */
class RecordVersement
{
    public function __construct(private AllocateVersement $allocate) {}

    public function handle(ClientProject $project, array $data, User $actor): Versement
    {
        $versement = DB::transaction(function () use ($project, $data, $actor) {
            $item = null;
            if (! empty($data['schedule_item_id'])) {
                // Lock the instalment row so concurrent payments can't lose an
                // update on paid_amount (money integrity, like ReserveUnit's hold).
                $item = PaymentSchedule::query()->active()
                    ->where('client_project_id', $project->id)
                    ->lockForUpdate()
                    ->findOrFail($data['schedule_item_id']);
            }

            // The payment tracks ONE apartment: sent explicitly, or inherited
            // from the instalment it settles. Both set → they must agree.
            $unitId = isset($data['unit_id']) ? (int) $data['unit_id'] : null;
            abort_if(
                $item !== null && $unitId !== null && $item->unit_id !== null
                    && (int) $item->unit_id !== $unitId,
                422,
                'This instalment belongs to another apartment.',
            );
            $unitId ??= $item?->unit_id !== null ? (int) $item->unit_id : null;

            $versement = Versement::create([
                'client_project_id' => $project->id,
                'unit_id' => $unitId,
                'amount' => $data['amount'],
                'paid_on' => $data['paid_on'],
                'method_id' => $data['method_id'],
                'reference' => $data['reference'] ?? null,
                'schedule_item_id' => $data['schedule_item_id'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            if ($item !== null) {
                $this->allocate->handle($item, (string) $versement->amount);
            }

            // A deposit on a not-yet-sold unit is a holding deposit: Reserve it
            // for this project.
            if ($unitId !== null) {
                $this->placeReservedLock(
                    $project,
                    $unitId,
                    $actor,
                    empty($data['reserved_until']) ? null : Carbon::parse($data['reserved_until']),
                );
            }

            // Return the created instance (not a refetch) so the API responds 201.
            return $versement;
        });

        // Notify the deal's owning agent (Collaboration listens; Phase 5).
        VersementRecorded::dispatch($versement);

        return $versement;
    }

    /**
     * The holding-deposit effect: a payment on a unit that is not yet sold
     * Reserves it for this project (available/interested → reserved) and starts
     * the expiry timer. Nobody else can buy it until it sells or the deposit
     * window lapses, though other projects may still queue as interested
     * backups. Recording further payments while already reserved does NOT reset
     * the timer, and a payment can never steal a unit held by another project.
     *
     * The deadline is per-deal: the agent picks it when filling the deposit
     * ($reservedUntil); left empty, the global reserved_hold_hours window
     * applies.
     */
    private function placeReservedLock(ClientProject $project, int $unitId, User $actor, ?Carbon $reservedUntil = null): void
    {
        $unit = Unit::whereKey($unitId)->lockForUpdate()->first();

        if ($unit === null || $unit->sale_status === SaleStatus::Sold) {
            return;
        }

        abort_if(
            $unit->sale_status === SaleStatus::Reserved
                && (int) $unit->reserved_project_id !== (int) $project->id,
            422,
            'This unit is reserved for another client.',
        );

        // The deposit backs a non-expiring hold for this project (so it counts
        // as an interest hold and survives the deposit window either way).
        $hasHold = Reservation::query()
            ->where('unit_id', $unitId)
            ->where('client_project_id', $project->id)
            ->where('hold_status', HoldStatus::Active->value)
            ->exists();

        if (! $hasHold) {
            Reservation::create([
                'unit_id' => $unitId,
                'client_project_id' => $project->id,
                'held_by' => $actor->id,
                'held_at' => now(),
                'expires_at' => null,
                'hold_status' => HoldStatus::Active->value,
            ]);
        }

        // Only the available/interested → reserved transition arms the timer; a later
        // instalment on an already-held unit leaves the deadline untouched.
        if ($unit->sale_status !== SaleStatus::Reserved) {
            $unit->update([
                'sale_status' => SaleStatus::Reserved->value,
                'reserved_project_id' => $project->id,
                'reserved_expires_at' => $reservedUntil
                    ?? now()->addHours(AppSetting::integer('reserved_hold_hours', 72)),
            ]);
        }
    }
}
