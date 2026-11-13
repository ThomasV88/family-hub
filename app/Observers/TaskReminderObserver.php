<?php

namespace App\Observers;

use App\Models\Task;
use App\Tasks\ReminderTimeCalculator;
use App\Tasks\TaskStatus;
use InvalidArgumentException;

/** Keeps tasks.remind_at (UTC) in step with the fields that decide it. */
class TaskReminderObserver
{
    private const WATCHED = [
        'due_date', 'due_time', 'remind_before_minutes', 'timezone', 'status', 'completed_at', 'skipped_at',
    ];

    public function __construct(private readonly ReminderTimeCalculator $calculator) {}

    public function saving(Task $task): void
    {
        if ($task->exists && ! $task->isDirty(self::WATCHED)) {
            return;
        }

        // An unset offset on a new row takes the column default (remind at due time).
        if (! $task->exists && ! array_key_exists('remind_before_minutes', $task->getAttributes())) {
            $task->remind_before_minutes = 0;
        }

        $task->remind_at = $this->compute($task);
    }

    private function compute(Task $task): ?\Carbon\CarbonImmutable
    {
        if ($task->status === TaskStatus::Done || $task->completed_at !== null || $task->skipped_at !== null) {
            return null;
        }
        if ($task->due_date === null || $task->remind_before_minutes === null) {
            return null;
        }

        try {
            $remindAt = $this->calculator->remindAt(
                $task->due_date->format('Y-m-d'),
                $task->due_time === null ? null : substr((string) $task->due_time, 0, 5),
                (string) $task->timezone,
                $task->remind_before_minutes,
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        // No retroactive reminders for tasks created or moved into the past.
        return $remindAt !== null && $remindAt->lt(now()) ? null : $remindAt;
    }
}
