<?php

namespace App\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Recurrence\LocalTime;
use App\Recurrence\RecurrenceEngine;
use App\Recurrence\RecurrenceMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Completes or skips one occurrence and creates the next one in the same transaction.
 * The row is re-read with a lock, so a second concurrent request sees it already closed and does nothing;
 * the partial unique index tasks_one_open_per_series is the backstop.
 */
class TaskCompletionService
{
    public function __construct(private readonly RecurrenceEngine $engine) {}

    /** Returns the next occurrence, or null when nothing was created (already completed, series ended, or not recurring). */
    public function complete(Task $task, User $by): ?Task
    {
        return $this->close($task, function (Task $locked) use ($by) {
            $locked->forceFill(['status' => TaskStatus::Done, 'completed_at' => now(), 'completed_by' => $by->id]);
        });
    }

    public function skip(Task $task, User $by): ?Task
    {
        return $this->close($task, function (Task $locked) {
            $locked->forceFill(['skipped_at' => now()]);
        });
    }

    /** @param  callable(Task): void  $mark */
    private function close(Task $task, callable $mark): ?Task
    {
        try {
            return DB::transaction(function () use ($task, $mark) {
                $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->first();
                if ($locked === null || $locked->completed_at !== null || $locked->skipped_at !== null) {
                    return null;
                }

                $mark($locked);
                $locked->save();
                $task->setRawAttributes($locked->getAttributes(), true);

                return $locked->isRecurring() ? $this->createNext($locked) : null;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request already opened the next occurrence of this series.
            return null;
        }
    }

    private function createNext(Task $done): ?Task
    {
        $tz = (string) $done->timezone;
        $time = $done->due_time === null ? null : substr((string) $done->due_time, 0, 5);
        $anchor = LocalTime::at($done->due_date->format('Y-m-d'), $time ?? '00:00', $tz);

        $next = $this->engine->next(
            (string) $done->recurrence_rule,
            $anchor,
            RecurrenceMode::from((string) $done->recurrence_mode),
            CarbonImmutable::now($tz),
            $tz,
            CarbonImmutable::now(),
        );
        if ($next === null) {
            return null;
        }

        $copy = new Task([
            'title' => $done->title,
            'notes' => $done->notes,
            'visibility' => $done->visibility,
            'assignee_id' => $done->assignee_id,
            'due_date' => $next->format('Y-m-d'),
            'due_time' => $time,
            'remind_before_minutes' => $done->remind_before_minutes,
            'recurrence_rule' => $done->recurrence_rule,
            'recurrence_mode' => $done->recurrence_mode,
        ]);
        $copy->forceFill([
            'creator_id' => $done->creator_id,
            'timezone' => $done->timezone,
            'series_id' => $done->series_id,
            'status' => TaskStatus::Todo,
        ]);
        $copy->save();
        $copy->members()->sync($done->members()->pluck('users.id')->all());

        return $copy;
    }
}
