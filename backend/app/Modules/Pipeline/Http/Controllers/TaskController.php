<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Pipeline\Actions\CancelTask;
use App\Modules\Pipeline\Actions\CompleteTask;
use App\Modules\Pipeline\Actions\CreateTask;
use App\Modules\Pipeline\Http\Requests\CompleteTaskRequest;
use App\Modules\Pipeline\Http\Requests\StoreTaskRequest;
use App\Modules\Pipeline\Http\Resources\TaskResource;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Settings\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Tasks (to-dos). All endpoints require tasks.manage; the team layer
 * (tasks.assign) additionally sees everyone's tasks, creates tasks for other
 * users and cancels any task — without it the board is strictly personal.
 */
class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $tasks = Task::query()
            ->with(['assignedTo', 'createdBy', 'subject'])
            ->active()
            // Without the team layer the board is personal, whatever the
            // requested scope; with it, scope=mine still narrows on demand.
            ->when(
                ! $user->can('tasks.assign') || $request->query('scope') === 'mine',
                fn ($q) => $q->where('assigned_to', $user->id),
            )
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->query('state')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->query('priority')))
            // overdue = still open and past due.
            ->when($request->boolean('overdue'), fn ($q) => $q->where('state', 'open')->whereNotNull('due_at')->where('due_at', '<=', now()))
            ->orderByRaw('due_at is null, due_at')
            ->get();

        return TaskResource::collection($tasks);
    }

    // One task per assignee (assigned_to_ids fans out); always a collection.
    public function store(StoreTaskRequest $request, CreateTask $action): JsonResponse
    {
        $tasks = $action->handle($request->validated(), $request->user());
        $tasks->each->load(['assignedTo', 'createdBy']);

        return TaskResource::collection($tasks)->response()->setStatusCode(201);
    }

    public function complete(CompleteTaskRequest $request, Task $task, CompleteTask $action): TaskResource
    {
        $this->authorizeTouch($request->user(), $task);

        return new TaskResource(
            $action->handle($task, $request->validated(), $request->user())->load(['assignedTo', 'createdBy', 'completedBy']),
        );
    }

    public function destroy(Request $request, Task $task, CancelTask $action): TaskResource
    {
        $this->authorizeTouch($request->user(), $task);

        $reason = (string) $request->input('reason', 'Task cancelled');

        return new TaskResource($action->handle($task, $reason)->load('assignedTo'));
    }

    /**
     * Completing/cancelling someone else's task is a team-layer move; the
     * assignee (or the person who created the task) always may.
     */
    private function authorizeTouch(User $user, Task $task): void
    {
        abort_unless(
            $task->assigned_to === $user->id
                || $task->created_by === $user->id
                || $user->can('tasks.assign'),
            403,
        );
    }
}
