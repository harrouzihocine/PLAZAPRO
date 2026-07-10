<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Enums\TaskCategory;
use App\Modules\Pipeline\Enums\TaskPriority;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Create a to-do — for one assignee (`assigned_to`, defaults to the acting
 * user) or fanned out to several at once (`assigned_to_ids`), one task row per
 * person so each completes with their own report. Assigning to anyone else is
 * vetted upstream (tasks.assign) and notifies that assignee.
 */
class CreateTask
{
    /**
     * @return Collection<int, Task> the created task(s)
     */
    public function handle(array $data, User $actor): Collection
    {
        $assignees = collect($data['assigned_to_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($assignees->isEmpty()) {
            $assignees = collect([(int) ($data['assigned_to'] ?? $actor->id)]);
        }

        $tasks = DB::transaction(fn () => $assignees->map(fn (int $assignee) => Task::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? TaskCategory::FollowUp->value,
            'assigned_to' => $assignee,
            'created_by' => $actor->id,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'repeat_every_hours' => $data['repeat_every_hours'] ?? null,
            'state' => TaskState::Open->value,
        ])));

        foreach ($tasks as $task) {
            if ((int) $task->assigned_to !== $actor->id) {
                $task->assignedTo?->notify(new DomainNotification(
                    kind: 'task_assigned',
                    key: 'task_assigned',
                    params: ['name' => $actor->name, 'title' => $task->title],
                    link: '/tasks?task='.$task->id,
                    subjectType: Task::class,
                    subjectId: $task->id,
                ));
            }
        }

        return $tasks;
    }
}
