<?php

namespace App\Push;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Sends through minishlink/web-push with the environment VAPID keys. Never logs payloads or keys. */
class WebPushSender implements PushSender
{
    public function __construct(private readonly ?HandlerStack $handler = null) {}

    /**
     * minishlink v11 takes a PSR-18 client. Redirects are off: an allowlisted push host must not be able
     * to steer the server-side POST to another (internal) address.
     */
    private function client(): Client
    {
        return new Client(array_filter([
            'timeout' => 15,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'handler' => $this->handler,
        ], fn ($v) => $v !== null));
    }

    public function send(PushSubscription $subscription, array $message): PushResult
    {
        // The write-time check is not enough: rows can come from imports or manual edits.
        if (! PushEndpointRule::allowed($subscription->endpoint)) {
            return PushResult::Expired;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => (string) config('push.subject'),
                'publicKey' => (string) config('push.public_key'),
                'privateKey' => (string) config('push.private_key'),
            ]], [], $this->client());

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
            // The library counts a 3xx as delivered; with redirects off it is a refusal, not a delivery.
            $report->isSuccess() && ($status = $report->getResponse()?->getStatusCode()) !== null && $status >= 200 && $status < 300 => PushResult::Success,
            $report->isSubscriptionExpired() => PushResult::Expired,
            default => PushResult::Failed,
        };
    }
}
