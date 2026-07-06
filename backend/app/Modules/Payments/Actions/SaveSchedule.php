<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Enums\ScheduleState;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Create or replace ONE APARTMENT's instalment plan (each won apartment on the
 * deal is tracked alone; unit_id null only on legacy project-level plans). The
 * plan must reconcile exactly to the apartment's agreed price (bcmath, no float
 * drift). Replacing cancels the prior active instalments of that apartment
 * (no-delete) and inserts the new ones.
 *
 * Re-planning is allowed even after money came in (the client re-spreads the
 * remaining balance): the amount already paid pours back onto the new
 * instalments in due order, so states and balances stay exact. Refunded money
 * is history and allocates nothing.
 */
class SaveSchedule
{
    public function __construct(private AllocateVersement $allocate) {}

    /**
     * @param  array{unit_id?: int|null, installments: array<int, array{due_date: string, amount: string}>}  $data
     * @return Collection<int, PaymentSchedule>
     */
    public function handle(ClientProject $project, array $data): Collection
    {
        $unitId = isset($data['unit_id']) ? (int) $data['unit_id'] : null;
        $agreed = $project->agreedPriceForUnit($unitId);

        abort_if(
            $agreed === null,
            422,
            $unitId === null
                ? 'Set the deal total price before creating a payment schedule.'
                : 'This apartment has no agreed price on the won deal — it cannot take a schedule.',
        );

        $installments = $data['installments'];
        $planned = Money::sum(array_column($installments, 'amount'));

        abort_unless(
            Money::equals($planned, $agreed),
            422,
            "Instalments ({$planned}) must total the agreed price ({$agreed})."
        );

        return DB::transaction(function () use ($project, $unitId, $installments) {
            // No-delete: cancel the apartment's prior active plan before laying
            // down the new one (other apartments' plans stay untouched).
            $this->scheduleQuery($project, $unitId)->get()
                ->each(fn (PaymentSchedule $item) => $item->cancel('Schedule replaced'));

            foreach ($installments as $index => $installment) {
                PaymentSchedule::create([
                    'client_project_id' => $project->id,
                    'unit_id' => $unitId,
                    'installment_no' => $index + 1,
                    'due_date' => $installment['due_date'],
                    'amount' => $installment['amount'],
                    'state' => ScheduleState::Pending->value,
                    'paid_amount' => '0.00',
                ]);
            }

            $plan = $this->scheduleQuery($project, $unitId)
                ->orderBy('installment_no')->get();

            $this->reallocatePaid($project, $unitId, $plan);

            return $plan;
        });
    }

    /**
     * Pour what was already collected onto the new instalments, oldest due
     * first — each fills up to its amount, the last one absorbs any overflow.
     * The versement rows themselves stay untouched (immutable history; their
     * schedule_item_id keeps pointing at the cancelled plan they settled).
     *
     * @param  Collection<int, PaymentSchedule>  $plan
     */
    private function reallocatePaid(ClientProject $project, ?int $unitId, Collection $plan): void
    {
        $remaining = Money::sum(
            $project->versements()->active()
                ->whereNull('refunded_at')
                ->when($unitId !== null, fn ($q) => $q->where('unit_id', $unitId))
                ->when($unitId === null, fn ($q) => $q->whereNull('unit_id'))
                ->pluck('amount'),
        );

        foreach ($plan as $index => $item) {
            if (Money::compare($remaining, '0') <= 0) {
                break;
            }

            $isLast = $index === count($plan) - 1;
            $take = ! $isLast && Money::compare($remaining, (string) $item->amount) > 0
                ? (string) $item->amount
                : $remaining;

            $this->allocate->handle($item, $take);
            $remaining = Money::sub($remaining, $take);
        }
    }

    private function scheduleQuery(ClientProject $project, ?int $unitId)
    {
        return $project->paymentSchedules()->active()
            ->when($unitId !== null, fn ($q) => $q->where('unit_id', $unitId))
            ->when($unitId === null, fn ($q) => $q->whereNull('unit_id'));
    }
}
