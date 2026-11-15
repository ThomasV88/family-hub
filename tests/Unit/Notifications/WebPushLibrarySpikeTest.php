<?php

use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

it('generates VAPID keys as non-empty base64url strings', function () {
    $keys = VAPID::createVapidKeys();

    expect($keys)->toHaveKeys(['publicKey', 'privateKey']);
    foreach (['publicKey', 'privateKey'] as $k) {
        expect($keys[$k])->toBeString()->not->toBeEmpty()->toMatch('/^[A-Za-z0-9_-]+$/');
    }
});

it('exposes the v11 API names the push plans rely on', function () {
    expect(method_exists(WebPush::class, 'sendOneNotification'))->toBeTrue()
        ->and(method_exists(WebPush::class, 'queueNotification'))->toBeTrue()
        ->and(method_exists(WebPush::class, 'flush'))->toBeTrue()
        ->and(method_exists(Subscription::class, 'create'))->toBeTrue()
        ->and(method_exists(MessageSentReport::class, 'isSuccess'))->toBeTrue()
        ->and(method_exists(MessageSentReport::class, 'isSubscriptionExpired'))->toBeTrue();
});

it('builds a Subscription from an associative array without network', function () {
    $sub = Subscription::create([
        'endpoint' => 'https://push.example.test/abc',
        'publicKey' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'authToken' => 'tBHItJI5svbpez7KI4CCXg',
    ]);

    expect($sub->getEndpoint())->toBe('https://push.example.test/abc');
});
