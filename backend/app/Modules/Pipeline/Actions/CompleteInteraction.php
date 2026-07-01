<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * Complete a visit and record its **required** next action in one transaction.
 * Completing an interaction can never leave the pipeline without a next step — the
 * rule is validated in the FormRequest and re-checked here.
 */
class CompleteInteraction
{
    public function __construct(private CreateNextAction $createNextAction) {}

    public function handle(Visit $visit, array $data): Visit
    {
        abort_if($visit->isCompleted(), 422, 'This visit is already completed.');
        abort_if(empty($data['next_action']), 422, 'A next action is required to complete a visit.');

        return DB::transaction(function () use ($visit, $data) {
            $visit->update([
                'completed_at' => now(),
                'outcome_id' => $data['outcome_id'] ?? $visit->outcome_id,
                'notes' => $data['notes'] ?? $visit->notes,
            ]);

            $subject = $visit->client_project_id
                ? ClientProject::findOrFail($visit->client_project_id)
                : Client::findOrFail($visit->client_id);

            $this->createNextAction->handle($subject, $visit, $data['next_action']);

            return $visit->fresh();
        });
    }
}
