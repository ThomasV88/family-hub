<?php

namespace App\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    /** @param  array<string, mixed>  $validated */
    public function create(User $creator, array $validated): Task
    {
        return DB::transaction(function () use ($creator, $validated) {
            $visibility = TaskVisibility::from($validated['visibility'] ?? TaskVisibility::Family->value);
            $memberIds = $this->memberIds($validated);

            $task = new Task($this->fields($validated));
            $task->forceFill([
                'creator_id' => $creator->id,
                'assignee_id' => array_key_exists('assignee_id', $validated) ? $validated['assignee_id'] : $creator->id,
                'timezone' => config('family.timezone'),
                'status' => TaskStatus::Todo,
                'visibility' => $visibility,
            ]);
            $this->enforceVisibility($task, $visibility, $memberIds);
            $this->normalizeDue($task);
            $task->save();
            $this->syncMembers($task, $visibility, $memberIds);

            if (isset($validated['status'])) {
                $this->applyStatus($task, TaskStatus::from($validated['status']), $creator)->save();
            }

            return $task;
        });
    }

    /** @param  array<string, mixed>  $validated */
    public function update(Task $task, array $validated, ?User $actor = null, bool $mayChangeVisibility = false): Task
    {
        return DB::transaction(function () use ($task, $validated, $actor, $mayChangeVisibility) {
            $task->fill($this->fields($validated));

            $visibility = $task->visibility;
            if ($mayChangeVisibility && isset($validated['visibility'])) {
                $visibility = TaskVisibility::from($validated['visibility']);
            }
            $memberIds = array_key_exists('member_ids', $validated) && $mayChangeVisibility
                ? $this->memberIds($validated)
                : $task->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            $this->enforceVisibility($task, $visibility, $memberIds);
            $task->forceFill(['visibility' => $visibility]);
            $this->normalizeDue($task);

            if (isset($validated['status']) && $actor !== null) {
                $this->applyStatus($task, TaskStatus::from($validated['status']), $actor);
            }
            $task->save();
            $this->syncMembers($task, $visibility, $memberIds);

            return $task;
        });
    }

    public function setStatus(Task $task, TaskStatus $status, User $by): Task
    {
        return DB::transaction(function () use ($task, $status, $by) {
            $this->applyStatus($task, $status, $by)->save();

            return $task;
        });
    }

    public function delete(Task $task): void
    {
        DB::transaction(fn () => $task->delete());
    }

    /**
     * Editable scalar fields only; status is handled separately so completion data stays consistent.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function fields(array $validated): array
    {
        return array_intersect_key($validated, array_flip(['title', 'notes', 'assignee_id', 'due_date', 'due_time']));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    private function memberIds(array $validated): array
    {
        return array_values(array_unique(array_map('intval', $validated['member_ids'] ?? [])));
    }

    /**
     * Personal stays with the creator; a Selected task may only be assigned to the creator or a chosen member.
     *
     * @param  list<int>  $memberIds
     */
    private function enforceVisibility(Task $task, TaskVisibility $visibility, array $memberIds): void
    {
        if ($visibility === TaskVisibility::Personal) {
            $task->assignee_id = $task->creator_id;

            return;
        }
        if ($visibility === TaskVisibility::Selected && $task->assignee_id !== null
            && $task->assignee_id !== $task->creator_id && ! in_array($task->assignee_id, $memberIds, true)) {
            throw ValidationException::withMessages(['assignee_id' => 'todo.error.assignee']);
        }
    }

    /** @param  list<int>  $memberIds */
    private function syncMembers(Task $task, TaskVisibility $visibility, array $memberIds): void
    {
        if ($visibility !== TaskVisibility::Selected) {
            $task->members()->detach();

            return;
        }
        $ids = array_merge($memberIds, [$task->creator_id], $task->assignee_id !== null ? [$task->assignee_id] : []);
        $task->members()->sync(array_values(array_unique($ids)));
    }

    /** Clearing the due date clears the time (a time without a date violates the schema). */
    private function normalizeDue(Task $task): void
    {
        if ($task->due_date === null) {
            $task->due_time = null;
        }
    }

    private function applyStatus(Task $task, TaskStatus $status, User $by): Task
    {
        if ($status === TaskStatus::Done) {
            if ($task->status !== TaskStatus::Done || $task->completed_at === null) {
                $task->forceFill(['completed_at' => now(), 'completed_by' => $by->id]);
            }
        } else {
            $task->forceFill(['completed_at' => null, 'completed_by' => null]);
        }
        $task->forceFill(['status' => $status]);

        return $task;
    }
}
