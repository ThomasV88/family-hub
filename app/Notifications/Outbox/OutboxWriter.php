<?php

namespace App\Notifications\Outbox;

use App\Models\OutboxItem;
use App\Models\User;

class OutboxWriter
{
    /**
     * Insert-or-ignore on the unique dedupe key. Payload carries ids and timestamps only, never titles or notes.
     *
     * @param  array<string, mixed>  $payload
     * @return bool true when a row was inserted
     */
    public function enqueue(string $kind, User $user, string $channel, string $subjectType, int $subjectId, string $dedupeKey, array $payload): bool
    {
        return OutboxItem::query()->insertOrIgnore([
            'kind' => $kind,
            'user_id' => $user->id,
            'channel' => $channel,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'dedupe_key' => $dedupeKey,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }
}
