<?php

namespace App\Notifications\Outbox;

use App\Models\OutboxItem;
use App\Models\User;

/** One implementation per outbox kind; the sender never knows what a kind means. */
interface OutboxHandler
{
    public function kind(): string;

    /** Re-checked at send time: false marks the row skipped and nothing is sent. */
    public function stillRelevant(OutboxItem $item, User $user): bool;

    public function sendMail(OutboxItem $item, User $user): void;

    /** @return array{title: string, url: string} */
    public function pushMessage(OutboxItem $item, User $user): array;
}
