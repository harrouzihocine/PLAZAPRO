<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Enums\TaskCategory;
use App\Modules\Pipeline\Enums\TaskPriority;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Settings\Models\User;

/**
 * Create a to-do. Defaults to the acting user, follow-up category and normal
 * priority. Assigning to someone else is vetted upstream (tasks.assign) and
 * notifies the assignee.
 */
class CreateTask
{
    public function handle(array $data, User $actor): Task
    {
        $task = Task::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? TaskCategory::FollowUp->value,
            'assigned_to' => $data['assigned_to'] ?? $actor->id,
            'created_by' => $actor->id,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'repeat_every_hours' => $data['repeat_every_hours'] ?? null,
            'state' => TaskState::Open->value,
        ]);

        if ($task->assigned_to !== $actor->id) {
            $task->assignedTo?->notify(new DomainNotification(
                kind: 'task_assigned',
                key: 'task_assigned',
                params: ['name' => $actor->name, 'title' => $task->title],
                link: '/tasks',
                subjectType: Task::class,
                subjectId: $task->id,
            ));
        }

        return $task;
    }
}
