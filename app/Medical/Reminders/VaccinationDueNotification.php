<?php

namespace App\Medical\Reminders;

use App\Notifications\Concerns\PlainTextMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Vaccination due mail, sent immediately by the outbox job. Names the person and links to the record. The vaccine
 * and any date are deliberately omitted (UI-SPEC privacy section): the mailbox is not a safe place for them.
 */
class VaccinationDueNotification extends Notification
{
    use PlainTextMail;

    public function __construct(public readonly string $personId, public readonly string $personName) {}

    public static function url(string $personId): string
    {
        return '/health/people/'.$personId;
    }

    /** Subject line and push title: plain text. */
    public static function title(string $personName, string $locale): string
    {
        return trans('notifications.vaccination_title', ['person' => $personName], $locale);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = method_exists($notifiable, 'preferredLocale') ? ($notifiable->preferredLocale() ?? 'en') : 'en';
        $greeting = trans('notifications.reminder_greeting', ['name' => self::plain($notifiable->name)], $locale);
        $body = trans('notifications.vaccination_body', ['person' => self::plain($this->personName)], $locale);
        $action = trans('notifications.vaccination_action', [], $locale);
        $salutation = trans('mail.salutation', [], $locale);
        $url = url(self::url($this->personId));

        $mail = (new MailMessage)
            ->subject(self::title($this->personName, $locale))
            ->greeting($greeting)
            ->line($body)
            ->action($action, $url)
            ->salutation($salutation);

        $plain = implode("\n\n", [
            trans('notifications.reminder_greeting', ['name' => $notifiable->name], $locale),
            trans('notifications.vaccination_body', ['person' => $this->personName], $locale),
            $action.': '.$url,
            $salutation,
        ]);

        return self::withPlainPart($mail, $plain);
    }
}
