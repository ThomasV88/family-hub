<?php

namespace App\Http\Controllers\Todo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Todo\UpdateTaskStatusRequest;
use App\Models\Task;
use App\Tasks\TaskAccess;
use App\Tasks\TaskService;
use App\Tasks\TaskStatus;
use Illuminate\Http\RedirectResponse;

class TaskStatusController extends Controller
{
    public function __invoke(UpdateTaskStatusRequest $request, int $task, TaskAccess $access, TaskService $service): RedirectResponse
    {
        $user = $request->user();
        $model = Task::query()->visibleTo($user)->findOrFail($task);
        abort_unless($access->mayChangeTodoStatus($user), 403);
        // History rows of a recurring task cannot be un-done (two open occurrences would result).
        $access->abortIfClosed($model);

        $service->setStatus($model, TaskStatus::from($request->validated('status')), $user);

        return NextOccurrence::redirect($model);
    }
}
