<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Actions\SaveSchedule;
use App\Modules\Payments\Http\Requests\SaveScheduleRequest;
use App\Modules\Payments\Http\Resources\PaymentScheduleResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The instalment plan for a deal. Reading needs versements.view; creating or
 * replacing the plan needs versements.record. The plan must reconcile to the
 * deal's agreed total_price — enforced server-side by SaveSchedule.
 */
class PaymentScheduleController extends Controller
{
    public function index(ClientProject $project): AnonymousResourceCollection
    {
        $schedule = $project->paymentSchedules()
            ->active()
            ->orderBy('installment_no')
            ->get();

        return PaymentScheduleResource::collection($schedule);
    }

    public function save(SaveScheduleRequest $request, ClientProject $project, SaveSchedule $action): AnonymousResourceCollection
    {
        $schedule = $action->handle($project, $request->validated());

        return PaymentScheduleResource::collection($schedule);
    }
}
