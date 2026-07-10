<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mark a task done with its completion report (what was done, outcome,
 * difficulties, time spent). A recurring task (repeat_every_hours) respawns
 * as a fresh open occurrence due that many hours from completion, and the
 * team layer (tasks.assign holders) is told the report is ready for review.
 */
class CompleteTask
{
    public function handle(Task $task, array $report, User $actor): Task
    {
        $task = DB::transaction(function () use ($task, $report, $actor) {
            $task->update([
                'state' => TaskState::Done->value,
                'completed_at' => now(),
                'completed_by' => $actor->id,
                'completion_outcome' => $report['outcome'],
                'completion_summary' => $report['summary'],
                'completion_difficulties' => $report['difficulties'] ?? null,
                'time_spent_minutes' => $report['time_spent_minutes'] ?? null,
            ]);

            if ($task->repeat_every_hours) {
                Task::create([
                    'title' => $task->title,
                    'description' => $task->description,
                    'category' => $task->category?->value,
                    'assigned_to' => $task->assigned_to,
                    'created_by' => $task->created_by,
                    'subject_type' => $task->subject_type,
                    'subject_id' => $task->subject_id,
                    'due_at' => now()->addHours($task->repeat_every_hours),
                    'priority' => $task->priority?->value,
                    'repeat_every_hours' => $task->repeat_every_hours,
                    'state' => TaskState::Open->value,
                ]);
            }

            return $task->fresh();
        });

        $this->notifyReviewers($task, $actor);

        return $task;
    }

    /**
     * Tell everyone who oversees the board (tasks.assign, plus super admins,
     * who pass every gate) that this report is in — except the completer.
     */
    private function notifyReviewers(Task $task, User $actor): void
    {
        $reviewers = User::query()->active()
            ->where('is_active', true)
            ->where('id', '!=', $actor->id)
            ->where(fn ($q) => $q
                ->whereHas('role.permissions', fn ($p) => $p->where('slug', 'tasks.assign'))
                ->orWhereHas('role', fn ($r) => $r->where('slug', 'super-admin')))
            ->get();

        foreach ($reviewers as $reviewer) {
            $reviewer->notify(new DomainNotification(
                kind: 'task_completed',
                key: 'task_completed',
                params: [
                    'name' => $actor->name,
                    'title' => $task->title,
                    'outcome' => '@notifications.task_outcome.'.$task->completion_outcome->value,
                ],
                link: '/tasks',
                subjectType: Task::class,
                subjectId: $task->id,
            ));
        }
    }
}
