<?php

namespace App\Notifications\Outbox;

use App\Models\OutboxItem;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskReminderNotification;
use App\Tasks\ReminderRecipients;
use App\Tasks\TaskStatus;

class TaskReminderHandler implements OutboxHandler
{
    public function __construct(private readonly ReminderRecipients $recipients) {}

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
            && $this->recipients->canSee($user, $task);
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
