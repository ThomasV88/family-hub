<?php

namespace App\Wishlist\Occasions;

use App\Models\User;
use App\Models\WishReminderSetting;
use InvalidArgumentException;

/** How many days ahead a member wants gift reminders. Empty list means none. */
final class ReminderLeadDays
{
    /** @return list<int> sorted descending */
    public function for(User $user): array
    {
        $row = WishReminderSetting::query()->find($user->id);
        $days = $row === null ? config('wishlist.default_lead_days') : $row->lead_days;
        $days = array_values(array_map('intval', (array) $days));
        rsort($days);

        return $days;
    }

    /** @param  list<int>  $days */
    public function set(User $user, array $days): void
    {
        $allowed = config('wishlist.allowed_lead_days');
        $days = array_values(array_unique(array_map('intval', $days)));
        if (array_diff($days, $allowed) !== []) {
            throw new InvalidArgumentException('Unsupported lead day.');
        }
        rsort($days);

        WishReminderSetting::query()->upsert(
            [['user_id' => $user->id, 'lead_days' => json_encode($days), 'created_at' => now(), 'updated_at' => now()]],
            ['user_id'],
            ['lead_days', 'updated_at'],
        );
    }
}
