<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Pipeline\Actions\CancelTask;
use App\Modules\Pipeline\Actions\CompleteTask;
use App\Modules\Pipeline\Actions\CreateTask;
use App\Modules\Pipeline\Http\Requests\StoreTaskRequest;
use App\Modules\Pipeline\Http\Resources\TaskResource;
use App\Modules\Pipeline\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Tasks (to-dos). All endpoints require tasks.manage. The dedicated tasks page is
 * built in Phase 5; this is the API foundation.
 */
class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tasks = Task::query()
            ->with('assignedTo')
            ->active()
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->query('state')))
            ->orderByRaw('due_at is null, due_at')
            ->get();

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request, CreateTask $action): TaskResource
    {
        return new TaskResource(
            $action->handle($request->validated(), $request->user())->load('assignedTo'),
        );
    }

    public function complete(Task $task, CompleteTask $action): TaskResource
    {
        return new TaskResource($action->handle($task)->load('assignedTo'));
    }

    public function destroy(Request $request, Task $task, CancelTask $action): TaskResource
    {
        $reason = (string) $request->input('reason', 'Task cancelled');

        return new TaskResource($action->handle($task, $reason)->load('assignedTo'));
    }
}
