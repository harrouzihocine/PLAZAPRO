<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Record an instalment payment and, when it settles a schedule item, allocate it —
 * updating that item's paid_amount/state — all in one transaction. The versement
 * is immutable afterwards: corrections go through CorrectVersement (supersedeWith).
 */
class RecordVersement
{
    public function __construct(private AllocateVersement $allocate) {}

    public function handle(ClientProject $project, array $data, User $actor): Versement
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            $versement = Versement::create([
                'client_project_id' => $project->id,
                'amount' => $data['amount'],
                'paid_on' => $data['paid_on'],
                'method_id' => $data['method_id'],
                'reference' => $data['reference'] ?? null,
                'schedule_item_id' => $data['schedule_item_id'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            if (! empty($data['schedule_item_id'])) {
                $item = PaymentSchedule::query()->active()
                    ->where('client_project_id', $project->id)
                    ->findOrFail($data['schedule_item_id']);

                $this->allocate->handle($item, (string) $versement->amount);
            }

            // Return the created instance (not a refetch) so the API responds 201.
            return $versement;
        });
    }
}
