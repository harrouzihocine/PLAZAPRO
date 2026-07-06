<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\ConvertReservation;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Actions\ReserveUnit;
use App\Modules\Inventory\Http\Requests\ReserveUnitRequest;
use App\Modules\Inventory\Http\Resources\ReservationResource;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Routing\Controller;

/**
 * The 48-hour interest-hold lifecycle. All actions require units.interest.
 */
class ReservationController extends Controller
{
    public function reserve(ReserveUnitRequest $request, Unit $unit, ReserveUnit $action): ReservationResource
    {
        $reservation = $action->handle($unit, $request->validated(), $request->user());

        return new ReservationResource($reservation->load('unit'));
    }

    public function release(Reservation $reservation, ReleaseReservation $action): ReservationResource
    {
        return new ReservationResource($action->handle($reservation)->load('unit'));
    }

    public function convert(Reservation $reservation, ConvertReservation $action): ReservationResource
    {
        return new ReservationResource($action->handle($reservation)->load('unit'));
    }
}
