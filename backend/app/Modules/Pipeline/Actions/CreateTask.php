<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\TaskPriority;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Settings\Models\User;

/**
 * Create a to-do. Defaults to the acting user and normal priority.
 */
class CreateTask
{
    public function handle(array $data, User $actor): Task
    {
        return Task::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? $actor->id,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'state' => TaskState::Open->value,
        ]);
    }
}
