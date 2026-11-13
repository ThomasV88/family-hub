<?php

namespace App\Http\Controllers\Todo;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Http\Controllers\Controller;
use App\Http\Requests\Todo\StoreTaskRequest;
use App\Models\Task;
use App\Models\User;
use App\Tasks\TaskStatus;
use App\Tasks\TaskVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request, AccessGate $gate): Response
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

        return Inertia::render('Todo/Index', [
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
            ])->values(),
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Quick add: only the title comes from the request. Everything else is a server-side default.
        $task = new Task(['title' => $request->validated('title')]);
        $task->forceFill([
            'creator_id' => $user->id,
            'assignee_id' => $user->id,
            'timezone' => config('family.timezone'),
            'status' => TaskStatus::Todo,
            'visibility' => TaskVisibility::Family,
        ])->save();

        return back(303)
            ->with('flash_data', ['taskId' => $task->id])
            ->with('toast', ['key' => 'todo.quick.added']);
    }
}
