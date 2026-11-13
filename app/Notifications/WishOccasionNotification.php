<?php

namespace App\Notifications;

use App\Wishlist\Occasions\OccasionOccurrence;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Gift reminder mail, sent immediately by the outbox job. Names the person, the occasion and the date and
 * links to the list. Nothing else about the list ever appears here.
 */
class WishOccasionNotification extends Notification
{
    public function __construct(public readonly OccasionOccurrence $occurrence) {}

    /** Backslash-escape ASCII punctuation so a name or custom title cannot become a link, image or emphasis. */
    private static function plain(string $text): string
    {
        return preg_replace('/([!-\/:-@\[-`{-~])/', '\\\\$1', $text) ?? '';
    }

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
        $occasion = self::plain(self::occasionLabel($o, $locale));

        $body = ($o->ownerName === null || $o->ownerId === null)
            ? trans('notifications.gift_body_family', ['occasion' => $occasion, 'date' => $date], $locale)
            : trans('notifications.gift_body', ['name' => self::plain($o->ownerName), 'occasion' => $occasion, 'date' => $date], $locale);

        return (new MailMessage)
            ->subject(self::title($o, $locale))
            ->greeting(trans('notifications.reminder_greeting', ['name' => self::plain($notifiable->name)], $locale))
            ->line($body)
            ->action(trans('notifications.gift_action', [], $locale), url(self::url($o)))
            ->salutation(trans('mail.salutation', [], $locale));
    }
}
