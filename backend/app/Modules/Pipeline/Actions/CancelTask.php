<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Pipeline\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Cancel (no-delete) a task: flip its business state to cancelled and mark the
 * record cancelled. Both are kept in history.
 */
class CancelTask
{
    public function handle(Task $task, string $reason): Task
    {
        return DB::transaction(function () use ($task, $reason) {
            $task->update(['state' => TaskState::Cancelled->value]);

            return $task->cancel($reason);
        });
    }
}
