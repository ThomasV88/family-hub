<?php

namespace App\Notifications;

use App\Models\Task;
use App\Recurrence\LocalTime;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent immediately by the outbox job (the job itself is the queued unit). Shows title and due moment only. */
class TaskReminderNotification extends Notification
{
    public readonly int $taskId;

    public readonly string $title;

    public readonly string $dueDate;

    public readonly ?string $dueTime;

    public readonly string $timezone;

    public function __construct(Task $task)
    {
        $this->taskId = $task->id;
        $this->title = $task->title;
        $this->dueDate = $task->due_date->format('Y-m-d');
        $this->dueTime = $task->due_time === null ? null : substr((string) $task->due_time, 0, 5);
        $this->timezone = $task->timezone;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = method_exists($notifiable, 'preferredLocale') ? ($notifiable->preferredLocale() ?? 'en') : 'en';

        $due = LocalTime::at($this->dueDate, $this->dueTime, $this->timezone)->locale($locale);
        // LL plus the clock: the sk locale's LLL drops the month name and year.
        $date = $this->dueTime === null ? $due->isoFormat('LL') : $due->isoFormat('LL').' '.$due->format('H:i');

        return (new MailMessage)
            ->subject(trans('notifications.reminder_title', ['title' => $this->title], $locale))
            ->greeting(trans('notifications.reminder_greeting', ['name' => $notifiable->name], $locale))
            ->line(trans('notifications.reminder_body', ['title' => $this->title, 'date' => $date], $locale))
            ->action(trans('notifications.reminder_action', [], $locale), url('/todo?task='.$this->taskId))
            ->salutation(trans('mail.salutation', [], $locale));
    }
}
