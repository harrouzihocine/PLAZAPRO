<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Enums\ScheduleState;
use App\Modules\Payments\Models\PaymentSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentSchedule>
 */
class PaymentScheduleFactory extends Factory
{
    protected $model = PaymentSchedule::class;

    public function definition(): array
    {
        return [
            'client_project_id' => ClientProject::factory(),
            'installment_no' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'amount' => '1000.00',
            'state' => ScheduleState::Pending->value,
            'paid_amount' => '0.00',
        ];
    }
}
