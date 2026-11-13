<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the account owner a Google identity was just linked to their account (sign-in by verified email). */
class GoogleLinkedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = in_array($notifiable->locale ?? null, ['sk', 'pl', 'en'], true) ? $notifiable->locale : 'en';

        return (new MailMessage)
            ->subject(trans('mail.google_linked_subject', [], $locale))
            ->greeting(trans('mail.invite_greeting', [], $locale))
            ->line(trans('mail.google_linked_line', [], $locale))
            ->line(trans('mail.google_linked_action', [], $locale))
            ->salutation(trans('mail.salutation', [], $locale));
    }
}
