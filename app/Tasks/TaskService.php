<?php

namespace App\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Recurrence\RuleBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(private readonly TaskCompletionService $completion) {}

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
            $this->applyRecurrence($task, $validated);
            $task->save();
            $this->syncMembers($task, $visibility, $memberIds);

            if (isset($validated['status'])) {
                $this->changeStatus($task, TaskStatus::from($validated['status']), $creator);
            }

            return $task;
        });
    }

    /** @param  array<string, mixed>  $validated */
    public function update(Task $task, array $validated, ?User $actor = null, bool $mayChangeVisibility = false): Task
    {
        return DB::transaction(function () use ($task, $validated, $actor, $mayChangeVisibility) {
            $task->fill($this->fields($validated));
            $dueChanged = $task->isDirty('due_date');

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
            $this->applyRecurrence($task, $validated, $dueChanged);

            // A closed occurrence of a series is history; its status is never changed again.
            $status = isset($validated['status']) && $actor !== null && ! $task->isClosedOccurrence()
                ? TaskStatus::from($validated['status']) : null;
            $completing = $status === TaskStatus::Done && $task->isRecurring() && $task->completed_at === null;
            if ($status !== null && ! $completing) {
                $this->applyStatus($task, $status, $actor);
            }
            $task->save();
            $this->syncMembers($task, $visibility, $memberIds);

            if ($completing) {
                $next = $this->completion->complete($task, $actor);
                $task->setRelation('nextOccurrence', $next);
            }

            return $task;
        });
    }

    /** Done on a recurring task completes the occurrence and opens the next one (kept on the nextOccurrence relation). */
    public function setStatus(Task $task, TaskStatus $status, User $by): Task
    {
        return DB::transaction(function () use ($task, $status, $by) {
            $this->changeStatus($task, $status, $by);

            return $task;
        });
    }

    private function changeStatus(Task $task, TaskStatus $status, User $by): void
    {
        if ($status === TaskStatus::Done && $task->isRecurring() && $task->completed_at === null) {
            $task->setRelation('nextOccurrence', $this->completion->complete($task, $by));

            return;
        }
        $this->applyStatus($task, $status, $by)->save();
    }

    /**
     * recurrence key present: set or clear the rule (series_id is kept when cleared so history stays linked).
     * Absent: keep the rule, but a due date change re-anchors a monthly day. No due date means no repeat.
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyRecurrence(Task $task, array $validated, bool $dueChanged = false): void
    {
        if ($task->due_date === null) {
            $task->forceFill(['recurrence_rule' => null, 'recurrence_mode' => null]);

            return;
        }
        $due = $task->due_date->format('Y-m-d');

        if (array_key_exists('recurrence', $validated)) {
            $posted = $validated['recurrence'];
            if ($posted === null) {
                $task->forceFill(['recurrence_rule' => null, 'recurrence_mode' => null]);

                return;
            }
            $stored = TaskRecurrence::stored($task->recurrence_rule);
            $pattern = TaskRecurrence::pattern($posted, $due);
            // Saving another occurrence untouched must not re-anchor a clamped rule (day 31 stays 31 on Feb 28).
            if (! $dueChanged && $stored !== null) {
                $same = $stored->toArray();
                $new = $pattern->toArray();
                unset($same['monthDay'], $new['monthDay']);
                if ($same === $new) {
                    $pattern = $stored;
                }
            }
            $task->forceFill([
                'recurrence_rule' => RuleBuilder::toRrule($pattern),
                'recurrence_mode' => $validated['recurrence_mode'] ?? $task->recurrence_mode,
                'series_id' => $task->series_id ?? (string) Str::uuid(),
            ]);

            return;
        }

        if ($dueChanged && $task->recurrence_rule !== null) {
            $stored = TaskRecurrence::stored($task->recurrence_rule);
            if ($stored !== null && $stored->monthDay !== null) {
                $task->forceFill(['recurrence_rule' => TaskRecurrence::rule($stored->toArray(), $due)]);
            }
        }
        if (isset($validated['recurrence_mode']) && $task->recurrence_rule !== null) {
            $task->forceFill(['recurrence_mode' => $validated['recurrence_mode']]);
        }
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
        return array_intersect_key($validated, array_flip(['title', 'notes', 'assignee_id', 'due_date', 'due_time', 'remind_before_minutes']));
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
