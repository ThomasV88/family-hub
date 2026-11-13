<?php

namespace App\Notifications\Outbox;

use App\Models\OutboxItem;
use App\Models\User;
use App\Notifications\WishOccasionNotification;
use App\Wishlist\Occasions\OccasionCalendar;
use App\Wishlist\Occasions\OccasionGivers;
use App\Wishlist\Occasions\OccasionOccurrence;
use App\Wishlist\Occasions\ReminderLeadDays;

class WishOccasionHandler implements OutboxHandler
{
    public function __construct(
        private readonly OccasionCalendar $calendar,
        private readonly OccasionGivers $givers,
        private readonly ReminderLeadDays $leads,
    ) {}

    public function kind(): string
    {
        return 'wish_occasion';
    }

    public function stillRelevant(OutboxItem $item, User $user): bool
    {
        $occurrence = $this->occurrence($item);
        $lead = $item->payload['lead'] ?? null;

        return $occurrence !== null
            && $occurrence->daysAway >= 0
            && is_int($lead)
            && in_array($lead, $this->leads->for($user), true)
            && $this->givers->isGiver($user, $occurrence);
    }

    public function sendMail(OutboxItem $item, User $user): void
    {
        $occurrence = $this->occurrence($item) ?? throw new \RuntimeException('Occasion missing.');

        $user->notifyNow(new WishOccasionNotification($occurrence));
    }

    public function pushMessage(OutboxItem $item, User $user): array
    {
        $occurrence = $this->occurrence($item) ?? throw new \RuntimeException('Occasion missing.');

        return [
            'title' => mb_strimwidth(WishOccasionNotification::title($occurrence, $user->preferredLocale() ?? 'en'), 0, 120, '…'),
            'url' => WishOccasionNotification::url($occurrence),
        ];
    }

    private function occurrence(OutboxItem $item): ?OccasionOccurrence
    {
        $date = $item->payload['date'] ?? null;

        return is_string($date)
            ? $this->calendar->occurrenceOn($item->subject_type, (int) $item->subject_id, $date)
            : null;
    }
}
