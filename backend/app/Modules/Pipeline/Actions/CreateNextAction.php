<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Models\NextAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Create the enforced next action for a subject (client/project), keeping the
 * invariant that a subject has **exactly one** open `pending` action: any prior
 * pending action is marked done first. `source` is the call/visit that produced it.
 */
class CreateNextAction
{
    /**
     * @param  array{type: string, due_at: mixed, assigned_to: int|string}  $data
     */
    public function handle(Model $subject, ?Model $source, array $data): NextAction
    {
        return DB::transaction(function () use ($subject, $source, $data) {
            // Close any prior open action so exactly one stays pending. Done per
            // model (not a bulk update) so each transition is audited (LogsActivity).
            $priorPending = NextAction::query()
                ->pending()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->get();

            foreach ($priorPending as $prior) {
                $prior->update([
                    'state' => NextActionState::Done->value,
                    'completed_at' => now(),
                ]);
            }

            return NextAction::create([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'type' => $data['type'],
                'due_at' => $data['due_at'],
                'assigned_to' => $data['assigned_to'],
                'state' => NextActionState::Pending->value,
            ]);
        });
    }
}
