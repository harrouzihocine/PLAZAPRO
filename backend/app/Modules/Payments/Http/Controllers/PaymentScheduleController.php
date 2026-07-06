<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Actions\SaveSchedule;
use App\Modules\Payments\Http\Requests\SaveScheduleRequest;
use App\Modules\Payments\Http\Resources\PaymentScheduleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The instalment plans on a deal — one per won apartment (?unit_id scopes the
 * read). Reading needs versements.view; creating or replacing a plan needs
 * versements.record. Each plan must reconcile to its apartment's agreed price —
 * enforced server-side by SaveSchedule.
 */
class PaymentScheduleController extends Controller
{
    public function index(Request $request, ClientProject $project): AnonymousResourceCollection
    {
        $schedule = $project->paymentSchedules()
            ->active()
            ->when(
                $request->filled('unit_id'),
                fn ($q) => $q->where('unit_id', (int) $request->query('unit_id')),
            )
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
