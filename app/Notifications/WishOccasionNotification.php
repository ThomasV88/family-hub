<?php

namespace App\Notifications;

use App\Notifications\Concerns\PlainTextMail;
use App\Wishlist\Occasions\OccasionOccurrence;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Gift reminder mail, sent immediately by the outbox job. Names the person, the occasion and the date and
 * links to the list. Nothing else about the list ever appears here.
 */
class WishOccasionNotification extends Notification
{
    use PlainTextMail;

    public function __construct(public readonly OccasionOccurrence $occurrence) {}

    public static function url(OccasionOccurrence $occurrence): string
    {
        return $occurrence->ownerId === null ? '/wishlist' : '/wishlist?member='.$occurrence->ownerId;
    }

    public static function occasionLabel(OccasionOccurrence $occurrence, string $locale): string
    {
        return match ($occurrence->type) {
            OccasionOccurrence::BIRTHDAY => trans('notifications.gift_birthday', [], $locale),
            OccasionOccurrence::NAMEDAY => trans('notifications.gift_nameday', [], $locale),
            OccasionOccurrence::CHRISTMAS => trans('notifications.gift_christmas', [], $locale),
            default => (string) $occurrence->title,
        };
    }

    /** Subject line and push title: plain text. */
    public static function title(OccasionOccurrence $occurrence, string $locale): string
    {
        $when = $occurrence->daysAway <= 0
            ? trans('notifications.gift_today', [], $locale)
            : trans_choice('notifications.gift_in_days', $occurrence->daysAway, [], $locale);
        $params = ['occasion' => self::occasionLabel($occurrence, $locale), 'when' => $when];

        if ($occurrence->ownerName === null || $occurrence->ownerId === null) {
            return trans('notifications.gift_title_family', $params, $locale);
        }

        return trans('notifications.gift_title', $params + ['name' => $occurrence->ownerName], $locale);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = method_exists($notifiable, 'preferredLocale') ? ($notifiable->preferredLocale() ?? 'en') : 'en';
        $o = $this->occurrence;
        $date = $o->date->locale($locale)->isoFormat('LL');
        $escape = fn (string $text): string => self::plain($text);
        $body = $this->body($o, $locale, $date, $escape);
        $greeting = trans('notifications.reminder_greeting', ['name' => $escape($notifiable->name)], $locale);
        $action = trans('notifications.gift_action', [], $locale);
        $salutation = trans('mail.salutation', [], $locale);
        $url = url(self::url($o));

        $mail = (new MailMessage)
            ->subject(self::title($o, $locale))
            ->greeting($greeting)
            ->line($body)
            ->action($action, $url)
            ->salutation($salutation);

        // The Markdown part needs the escapes above; the text/plain alternative is real plain text without them.
        $plain = implode("\n\n", [
            trans('notifications.reminder_greeting', ['name' => $notifiable->name], $locale),
            $this->body($o, $locale, $date, fn (string $text): string => $text),
            $action.': '.$url,
            $salutation,
        ]);

        return self::withPlainPart($mail, $plain);
    }

    /** @param  callable(string): string  $escape */
    private function body(OccasionOccurrence $o, string $locale, string $date, callable $escape): string
    {
        $occasion = $escape(self::occasionLabel($o, $locale));

        return ($o->ownerName === null || $o->ownerId === null)
            ? trans('notifications.gift_body_family', ['occasion' => $occasion, 'date' => $date], $locale)
            : trans('notifications.gift_body', ['name' => $escape($o->ownerName), 'occasion' => $occasion, 'date' => $date], $locale);
    }
}
