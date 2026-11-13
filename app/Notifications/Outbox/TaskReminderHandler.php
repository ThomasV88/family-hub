<?php

namespace App\Notifications\Outbox;

use App\Models\OutboxItem;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskReminderNotification;
use App\Tasks\ReminderRecipients;
use App\Tasks\ReminderTimeCalculator;
use App\Tasks\TaskStatus;

class TaskReminderHandler implements OutboxHandler
{
    public function __construct(
        private readonly ReminderRecipients $recipients,
        private readonly ?ReminderTimeCalculator $calculator = null,
    ) {}

    public function kind(): string
    {
        return 'task_reminder';
    }

    public function stillRelevant(OutboxItem $item, User $user): bool
    {
        $task = $this->task($item);

        return $task !== null
            && $task->status !== TaskStatus::Done
            && $task->completed_at === null
            && $task->skipped_at === null
            && $this->sameReminderMoment($item, $task)
            && $this->isRecipient($user, $task);
    }

    /**
     * The row was written for one reminder instant. If the due date, time or offset moved since, the
     * task's current reminder instant differs and this (possibly retrying) row is stale.
     */
    private function sameReminderMoment(OutboxItem $item, Task $task): bool
    {
        $payloadAt = $item->payload['remind_at'] ?? null;
        if (! is_int($payloadAt) || $task->due_date === null || $task->remind_before_minutes === null) {
            return false;
        }

        try {
            $expected = ($this->calculator ?? new ReminderTimeCalculator)->remindAt(
                $task->due_date->format('Y-m-d'),
                $task->due_time === null ? null : substr((string) $task->due_time, 0, 5),
                (string) $task->timezone,
                $task->remind_before_minutes,
            );
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $expected !== null && $expected->timestamp === $payloadAt;
    }

    /** Reassigned or made Personal since the row was written: only the current recipient still gets it. */
    private function isRecipient(User $user, Task $task): bool
    {
        foreach ($this->recipients->for($task) as $recipient) {
            if ($recipient->id === $user->id) {
                return true;
            }
        }

        return false;
    }

    public function sendMail(OutboxItem $item, User $user): void
    {
        $task = $this->task($item) ?? throw new \RuntimeException('Task missing.');

        $user->notifyNow(new TaskReminderNotification($task));
    }

    public function pushMessage(OutboxItem $item, User $user): array
    {
        $task = $this->task($item) ?? throw new \RuntimeException('Task missing.');

        return [
            'title' => trans('notifications.reminder_title', ['title' => mb_strimwidth($task->title, 0, 80, '…')], $user->preferredLocale() ?? 'en'),
            'url' => '/todo?task='.$task->id,
        ];
    }

    private function task(OutboxItem $item): ?Task
    {
        return Task::query()->find((int) ($item->payload['task_id'] ?? 0));
    }
}
