<?php

namespace Tests\Support;

use App\Models\PushSubscription;
use App\Push\PushResult;
use App\Push\PushSender;

/** Records messages instead of talking to a push service. */
class FakePushSender implements PushSender
{
    /** @var list<array{subscription: int, message: array}> */
    public array $sent = [];

    /** @var array<int, PushResult> per subscription id; default Success */
    public array $results = [];

    public function send(PushSubscription $subscription, array $message): PushResult
    {
        $this->sent[] = ['subscription' => $subscription->id, 'message' => $message];

        return $this->results[$subscription->id] ?? PushResult::Success;
    }
}
