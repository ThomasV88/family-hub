<?php

namespace App\Http\Controllers\Todo;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Http\Controllers\Controller;
use App\Http\Requests\Todo\StoreTaskRequest;
use App\Http\Requests\Todo\UpdateTaskRequest;
use App\Models\Task;
use App\Models\User;
use App\Tasks\TaskAccess;
use App\Tasks\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request, AccessGate $gate, TaskAccess $access): Response
    {
        $user = $request->user();
        $timezone = config('family.timezone');

        $tasks = Task::query()
            ->visibleTo($user)
            ->whereNull('tasks.skipped_at')
            ->where(fn ($q) => $q->whereNull('tasks.completed_at')->orWhere('tasks.completed_at', '>=', now()->subDays(30)))
            ->with('members:id')
            ->orderBy('tasks.id')
            ->get();

        // Only people who can themselves open the To-do module get a column. Names only, never emails.
        $members = User::query()
            ->whereIn('users.id', fn ($q) => $q->select('user_id')->from('module_permissions')
                ->where('module', Module::Todo->value)
                ->where('level', '>=', AccessLevel::View->value))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name])
            ->values();

        // Same answer for an unknown id and one the user cannot see; 'new' opens the create panel.
        $requested = $request->query('task');
        $selectedTaskMissing = is_string($requested) && ctype_digit($requested)
            && ! Task::query()->visibleTo($user)->whereKey((int) $requested)->exists();

        return Inertia::render('Todo/Index', [
            'selectedTaskMissing' => $selectedTaskMissing,
            'timezone' => $timezone,
            'today' => now($timezone)->toDateString(),
            'canEdit' => $gate->allows($user, Module::Todo, AccessLevel::Edit),
            'members' => $members,
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'notes' => $t->notes,
                'status' => $t->status->value,
                'visibility' => $t->visibility->value,
                'creatorId' => $t->creator_id,
                'assigneeId' => $t->assignee_id,
                'dueDate' => $t->due_date?->format('Y-m-d'),
                'dueTime' => $t->due_time ? substr((string) $t->due_time, 0, 5) : null,
                'isRecurring' => $t->isRecurring(),
                'completedAt' => $t->completed_at?->toIso8601String(),
                'memberIds' => $t->members->pluck('id')->values()->all(),
                'abilities' => $access->abilities($user, $t),
            ])->values(),
        ]);
    }

    public function store(StoreTaskRequest $request, TaskService $service): RedirectResponse
    {
        $task = $service->create($request->user(), $request->validated());

        return back(303)
            ->with('flash_data', ['taskId' => $task->id])
            ->with('toast', ['key' => count($request->validated()) > 1 ? 'todo.toast.saved' : 'todo.quick.added']);
    }

    public function update(UpdateTaskRequest $request, int $task, TaskAccess $access, TaskService $service): RedirectResponse
    {
        $user = $request->user();
        $model = Task::query()->visibleTo($user)->findOrFail($task);
        abort_unless($access->canEdit($user, $model), 403);

        $service->update($model, $request->validated(), $user);

        return back(303)->with('toast', ['key' => 'todo.toast.saved']);
    }

    public function destroy(Request $request, int $task, TaskAccess $access, TaskService $service): RedirectResponse
    {
        $user = $request->user();
        $model = Task::query()->visibleTo($user)->findOrFail($task);
        abort_unless($access->canDelete($user, $model), 403);

        $service->delete($model);

        return back(303)->with('toast', ['key' => 'todo.toast.deleted']);
    }
}
