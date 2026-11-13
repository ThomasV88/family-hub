<?php

namespace App\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Sends through minishlink/web-push with the environment VAPID keys. Never logs payloads or keys. */
class WebPushSender implements PushSender
{
    public function send(PushSubscription $subscription, array $message): PushResult
    {
        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => (string) config('push.subject'),
                'publicKey' => (string) config('push.public_key'),
                'privateKey' => (string) config('push.private_key'),
            ]], [], 15);

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ['TTL' => 3600, 'urgency' => 'normal'],
            );
        } catch (\Throwable) {
            return PushResult::Failed;
        }

        return match (true) {
            $report->isSuccess() => PushResult::Success,
            $report->isSubscriptionExpired() => PushResult::Expired,
            default => PushResult::Failed,
        };
    }
}
