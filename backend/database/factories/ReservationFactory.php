<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        $heldAt = now();

        return [
            'unit_id' => Unit::factory(),
            'client_project_id' => null,
            'held_by' => User::factory(),
            'held_at' => $heldAt,
            'expires_at' => $heldAt->copy()->addHours(48),
            'hold_status' => HoldStatus::Active->value,
        ];
    }
}
