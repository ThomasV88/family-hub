<?php

namespace App\Push;

use App\Models\PushSubscription;

interface PushSender
{
    /** @param array<string, mixed> $message JSON payload shown by the service worker */
    public function send(PushSubscription $subscription, array $message): PushResult;
}
