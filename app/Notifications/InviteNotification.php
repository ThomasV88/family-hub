<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/** Queued; the plain token lives in the job payload only until it is sent (accepted risk T-02-36). */
class InviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $invitationId,
        public readonly string $inviterName,
        public readonly string $inviteLocale,
        #[\SensitiveParameter] public readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = in_array($this->inviteLocale, ['sk', 'pl', 'en'], true) ? $this->inviteLocale : 'en';

        return (new MailMessage)
            ->subject(trans('mail.invite_subject', [], $locale))
            ->greeting(trans('mail.invite_greeting', [], $locale))
            ->line(trans('mail.invite_line', ['inviter' => $this->inviterName], $locale))
            ->action(trans('mail.invite_action', [], $locale), url('/invite/'.$this->token))
            ->line(trans('mail.invite_expiry', [], $locale))
            ->line(trans('mail.invite_ignore', [], $locale))
            ->salutation(trans('mail.salutation', [], $locale));
    }

    /** Flags the invitation so the admin sees "Email not sent" and can still copy the link. */
    public function failed(?Throwable $exception = null): void
    {
        Invitation::query()->whereKey($this->invitationId)->update(['mail_failed_at' => now()]);
    }
}
