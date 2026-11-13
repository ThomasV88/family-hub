<?php

use App\Models\PushSubscription;
use App\Push\PushResult;
use App\Push\WebPushSender;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;

function senderSubscription(string $endpoint): PushSubscription
{
    return new PushSubscription([
        'endpoint' => $endpoint,
        'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'auth' => 'tBHItJI5svbpez7KI4CCXg',
        'content_encoding' => 'aes128gcm',
    ]);
}

beforeEach(function () {
    $keys = VAPID::createVapidKeys();
    config(['push.subject' => 'mailto:test@example.test', 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey']]);
});

it('sends to an allowlisted endpoint and maps the response', function () {
    $requests = [];
    $mock = new MockHandler([new Response(201), new Response(410)]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($requests));
    $sender = new WebPushSender($stack);

    expect($sender->send(senderSubscription('https://fcm.googleapis.com/fcm/send/abc'), ['title' => 't']))->toBe(PushResult::Success)
        ->and($sender->send(senderSubscription('https://fcm.googleapis.com/fcm/send/abc'), ['title' => 't']))->toBe(PushResult::Expired);
    expect($requests)->toHaveCount(2);
});

it('refuses an endpoint outside the allowlist at send time without any request', function () {
    $mock = new MockHandler([new Response(201)]);
    $sender = new WebPushSender(HandlerStack::create($mock));

    expect($sender->send(senderSubscription('http://169.254.169.254/latest'), ['title' => 't']))->toBe(PushResult::Expired)
        ->and($sender->send(senderSubscription('https://evil.example/push'), ['title' => 't']))->toBe(PushResult::Expired);
    expect($mock->count())->toBe(1);
});

it('does not follow redirects from a push host', function () {
    $mock = new MockHandler([
        new Response(302, ['Location' => 'http://169.254.169.254/']),
        new Response(201),
    ]);
    $sender = new WebPushSender(HandlerStack::create($mock));

    expect($sender->send(senderSubscription('https://fcm.googleapis.com/fcm/send/abc'), ['title' => 't']))->toBe(PushResult::Failed);
    expect($mock->count())->toBe(1);
});
