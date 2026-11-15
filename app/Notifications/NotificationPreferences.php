<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/** Per-user channel switches. No row means the default: mail on, push off. */
class NotificationPreferences
{
    public function enabled(User $user, string $channel): bool
    {
        $row = NotificationPreference::query()->where('user_id', $user->id)->where('channel', $channel)->first();

        return $row === null ? $channel === 'mail' : $row->enabled;
    }

    public function set(User $user, string $channel, bool $enabled): void
    {
        NotificationPreference::query()->upsert(
            [['user_id' => $user->id, 'channel' => $channel, 'enabled' => $enabled, 'created_at' => now(), 'updated_at' => now()]],
            ['user_id', 'channel'],
            ['enabled', 'updated_at'],
        );
    }
}
