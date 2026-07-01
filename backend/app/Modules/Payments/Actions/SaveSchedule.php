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
 * Create or replace a deal's instalment plan. The plan must reconcile exactly to
 * the deal's agreed total_price (bcmath, no float drift). Replacing cancels the
 * prior active instalments (no-delete) and inserts the new ones. Refused once any
 * versement has been recorded — correct the versements first.
 */
class SaveSchedule
{
    /**
     * @param  array{installments: array<int, array{due_date: string, amount: string}>}  $data
     * @return Collection<int, PaymentSchedule>
     */
    public function handle(ClientProject $project, array $data): Collection
    {
        abort_if(
            $project->total_price === null,
            422,
            'Set the deal total price before creating a payment schedule.'
        );

        abort_if(
            $this->hasRecordedVersements($project),
            422,
            'Cannot change the schedule once payments have been recorded; correct the versements first.'
        );

        $installments = $data['installments'];
        $planned = Money::sum(array_column($installments, 'amount'));

        abort_unless(
            Money::equals($planned, (string) $project->total_price),
            422,
            "Instalments ({$planned}) must total the agreed price ({$project->total_price})."
        );

        return DB::transaction(function () use ($project, $installments) {
            // No-delete: cancel the prior active plan before laying down the new one.
            $project->paymentSchedules()->active()->get()
                ->each(fn (PaymentSchedule $item) => $item->cancel('Schedule replaced'));

            foreach ($installments as $index => $installment) {
                PaymentSchedule::create([
                    'client_project_id' => $project->id,
                    'installment_no' => $index + 1,
                    'due_date' => $installment['due_date'],
                    'amount' => $installment['amount'],
                    'state' => ScheduleState::Pending->value,
                    'paid_amount' => '0.00',
                ]);
            }

            return $project->paymentSchedules()->active()
                ->orderBy('installment_no')->get();
        });
    }

    private function hasRecordedVersements(ClientProject $project): bool
    {
        return $project->versements()->active()->exists();
    }
}
