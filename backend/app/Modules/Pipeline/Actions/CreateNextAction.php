<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Models\NextAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Create the enforced next action for a subject (client/project), keeping the
 * invariant that a subject has **exactly one** open `pending` action: any prior
 * pending action is marked done first. `source` is the call/visit that produced it.
 *
 * "When" arrives as `due_date` + optional `due_time` (the UI splits them because an
 * agent usually only knows the day); a legacy `due_at` is still accepted so internal
 * callers (seeders) need not change. "Who" falls back to $defaultAssigneeId (the
 * client's sales agent) when the request left assigned_to blank.
 */
class CreateNextAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Model $subject, ?Model $source, array $data, ?int $defaultAssigneeId = null): NextAction
    {
        $assignedTo = $data['assigned_to'] ?? $defaultAssigneeId;
        abort_if($assignedTo === null, 422, 'A next action must be assigned to someone.');

        return DB::transaction(function () use ($subject, $source, $data, $assignedTo) {
            // Close any prior open action so exactly one stays pending. Only active
            // rows count (a superseded/cancelled action keeps its old state value).
            // Done per model (not a bulk update) so each transition is audited.
            // A project-level action ALSO closes the client-level pending (the
            // qualifying call's plan) — the story keeps ONE pending log to fill.
            $priorPending = NextAction::query()
                ->active()
                ->pending()
                ->where(function ($q) use ($subject) {
                    $q->where(fn ($s) => $s
                        ->where('subject_type', $subject->getMorphClass())
                        ->where('subject_id', $subject->getKey()));

                    if ($subject instanceof ClientProject) {
                        $q->orWhere(fn ($s) => $s
                            ->where('subject_type', 'client')
                            ->where('subject_id', $subject->client_id));
                    }
                })
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
                'due_at' => self::resolveDueAt($data),
                'assigned_to' => $assignedTo,
                'state' => NextActionState::Pending->value,
            ]);
        });
    }

    /**
     * Compose the timestamp from the split date + optional time. Falls back to a
     * legacy single `due_at` value when a caller supplies one directly. Shared with
     * CorrectNextAction so both build `due_at` the same way.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolveDueAt(array $data): Carbon
    {
        if (! empty($data['due_at'])) {
            return Carbon::parse($data['due_at']);
        }

        $date = Carbon::parse($data['due_date'])->startOfDay();

        if (! empty($data['due_time'])) {
            [$hour, $minute] = array_map('intval', explode(':', (string) $data['due_time']));
            $date->setTime($hour, $minute);
        }

        return $date;
    }
}
