<?php

namespace App\Http\Controllers\Todo;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Tasks\TaskAccess;
use App\Tasks\TaskCompletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskSkipController extends Controller
{
    public function __invoke(Request $request, int $task, TaskAccess $access, TaskCompletionService $completion): RedirectResponse
    {
        $user = $request->user();
        $model = Task::query()->visibleTo($user)->findOrFail($task);
        abort_unless($access->mayChangeTodoStatus($user), 403);
        $access->abortIfClosed($model);
        abort_unless($model->isRecurring(), 422);

        $model->setRelation('nextOccurrence', $completion->skip($model, $user));

        return NextOccurrence::redirect($model, 'todo.toast.skipped');
    }
}
