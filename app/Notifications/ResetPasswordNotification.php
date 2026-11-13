<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** Queued so SMTP latency can never reveal whether the email belongs to a member. */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = in_array($notifiable->locale ?? null, ['sk', 'pl', 'en'], true) ? $notifiable->locale : app()->getLocale();
        $url = URL::to('/reset-password/'.$this->token).'?email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject(trans('mail.reset_subject', [], $locale))
            ->greeting(trans('mail.invite_greeting', [], $locale))
            ->line(trans('mail.reset_line', [], $locale))
            ->action(trans('mail.reset_action', [], $locale), $url)
            ->line(trans('mail.reset_expiry', [], $locale))
            ->line(trans('mail.reset_ignore', [], $locale))
            ->salutation(trans('mail.salutation', [], $locale));
    }
}
