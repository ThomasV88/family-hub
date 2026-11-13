<?php

namespace App\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskService
{
    /** @param  array<string, mixed>  $validated */
    public function create(User $creator, array $validated): Task
    {
        return DB::transaction(function () use ($creator, $validated) {
            $task = new Task($this->fields($validated));
            $task->forceFill([
                'creator_id' => $creator->id,
                'assignee_id' => array_key_exists('assignee_id', $validated) ? $validated['assignee_id'] : $creator->id,
                'timezone' => config('family.timezone'),
                'status' => TaskStatus::Todo,
                'visibility' => TaskVisibility::Family,
            ]);
            $this->normalizeDue($task);
            $task->save();

            if (isset($validated['status'])) {
                $this->applyStatus($task, TaskStatus::from($validated['status']), $creator)->save();
            }

            return $task;
        });
    }

    /** @param  array<string, mixed>  $validated */
    public function update(Task $task, array $validated, ?User $actor = null): Task
    {
        return DB::transaction(function () use ($task, $validated, $actor) {
            $task->fill($this->fields($validated));
            $this->normalizeDue($task);

            if (isset($validated['status']) && $actor !== null) {
                $this->applyStatus($task, TaskStatus::from($validated['status']), $actor);
            }
            $task->save();

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
