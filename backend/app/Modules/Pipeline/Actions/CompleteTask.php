<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Models\Task;

/**
 * Mark a task done.
 */
class CompleteTask
{
    public function handle(Task $task): Task
    {
        $task->update(['state' => TaskState::Done->value]);

        return $task->fresh();
    }
}
