<?php

use App\Access\Preset;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\PushEndpointRule;
use App\Push\PushSender;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use Tests\Support\FakePushSender;

const P256 = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
const AUTHK = 'tBHItJI5svbpez7KI4CCXg';

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->user = User::factory()->withPreset(Preset::Parent)->create();
});

function subBody(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc', array $keys = ['p256dh' => P256, 'auth' => AUTHK]): array
{
    return ['endpoint' => $endpoint, 'keys' => $keys];
}

it('stores a subscription with encrypted keys, hash and label', function () {
    $this->actingAs($this->user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit Safari/605.1')
        ->postJson('/settings/notifications/push', subBody())
        ->assertCreated()->assertExactJson(['ok' => true]);

    $row = PushSubscription::query()->sole();
    expect($row->user_id)->toBe($this->user->id)
        ->and($row->endpoint_hash)->toBe(hash('sha256', 'https://fcm.googleapis.com/fcm/send/abc'))
        ->and($row->p256dh)->toBe(P256)->and($row->auth)->toBe(AUTHK)
        ->and($row->label)->toBe('Safari on iOS')
        ->and($row->content_encoding)->toBe('aes128gcm');
    $raw = DB::table('push_subscriptions')->first();
    expect($raw->p256dh)->not->toBe(P256)->and($raw->auth)->not->toBe(AUTHK);
});

it('rebinds an endpoint stored by another user', function () {
    $other = User::factory()->withPreset(Preset::Child)->create();
    $this->actingAs($other)->postJson('/settings/notifications/push', subBody())->assertCreated();
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody())->assertCreated();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->sole()->user_id)->toBe($this->user->id);
});

it('rejects unsafe endpoints', function (string $endpoint) {
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody($endpoint))
        ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'http' => 'http://fcm.googleapis.com/x',
    'ipv4' => 'https://127.0.0.1/x',
    'ipv6' => 'https://[::1]/x',
    'other host' => 'https://evil.example/x',
    'suffix trick' => 'https://fcm.googleapis.com.evil.example/x',
    'prefix trick' => 'https://notfcm.googleapis.com.evil.example/x',
    'userinfo' => 'https://user@fcm.googleapis.com/x',
    'port' => 'https://fcm.googleapis.com:8443/x',
    'backslash' => 'https://evil.example\\@fcm.googleapis.com/x',
    'too long' => fn () => 'https://fcm.googleapis.com/'.str_repeat('a', 2049 - 27),
]);

it('accepts real push hosts and their subdomains', function (string $endpoint) {
    expect(PushEndpointRule::allowed($endpoint))->toBeTrue();
})->with([
    'https://fcm.googleapis.com/fcm/send/x',
    'https://updates.push.services.mozilla.com/wpush/v2/x',
    'https://web.push.apple.com/x',
    'https://wns2-par02p.notify.windows.com/w/x',
]);

it('rejects bad key lengths', function () {
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody(keys: ['p256dh' => 'AAAA', 'auth' => AUTHK]))
        ->assertUnprocessable()->assertJsonValidationErrors('keys.p256dh');
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody(keys: ['p256dh' => P256, 'auth' => 'AAAA']))
        ->assertUnprocessable()->assertJsonValidationErrors('keys.auth');
});

it('keeps at most ten subscriptions, dropping the least recently updated', function () {
    foreach (range(1, 10) as $i) {
        $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody("https://fcm.googleapis.com/fcm/send/d$i"))->assertCreated();
    }
    $oldest = PushSubscription::query()->where('endpoint_hash', hash('sha256', 'https://fcm.googleapis.com/fcm/send/d1'))->first();
    DB::table('push_subscriptions')->where('id', $oldest->id)->update(['updated_at' => now()->subDay()]);

    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody('https://fcm.googleapis.com/fcm/send/d11'))->assertCreated();

    expect(PushSubscription::query()->where('user_id', $this->user->id)->count())->toBe(10)
        ->and(PushSubscription::query()->whereKey($oldest->id)->exists())->toBeFalse();
});

it('destroys only the callers own row', function () {
    $other = User::factory()->withPreset(Preset::Child)->create();
    $this->actingAs($other)->postJson('/settings/notifications/push', subBody('https://fcm.googleapis.com/fcm/send/theirs'))->assertCreated();
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody())->assertCreated();

    $this->actingAs($this->user)->deleteJson('/settings/notifications/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/theirs'])->assertNoContent();
    expect(PushSubscription::query()->count())->toBe(2);

    $this->actingAs($this->user)->deleteJson('/settings/notifications/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();
    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->sole()->user_id)->toBe($other->id);
});

it('test endpoint answers 422 without a subscription', function () {
    $this->actingAs($this->user)->postJson('/settings/notifications/push/test')
        ->assertUnprocessable()->assertExactJson(['error' => 'none']);
});

it('test endpoint answers 503 when VAPID is not configured', function () {
    config(['push.subject' => null, 'push.public_key' => null, 'push.private_key' => null]);
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody())->assertCreated();

    $this->actingAs($this->user)->postJson('/settings/notifications/push/test')
        ->assertStatus(503)->assertExactJson(['error' => 'not_configured']);
});

it('test endpoint sends the localized title through the sender', function () {
    $keys = VAPID::createVapidKeys();
    config(['push.subject' => 'mailto:test@example.test', 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey']]);
    $fake = new FakePushSender;
    app()->instance(PushSender::class, $fake);
    $this->user->forceFill(['locale' => 'sk'])->save();
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody())->assertCreated();

    $this->actingAs($this->user)->postJson('/settings/notifications/push/test')
        ->assertOk()->assertExactJson(['sent' => 1]);

    expect($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]['message']['title'])->toBe(trans('notifications.test_title', [], 'sk'))
        ->and($fake->sent[0]['message']['url'])->toBe('/settings/notifications');
});

it('removes only the logging-out device subscription when the logout request names its endpoint', function () {
    $mine = 'https://fcm.googleapis.com/fcm/send/mine';
    $other = 'https://fcm.googleapis.com/fcm/send/other';
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody($mine))->assertCreated();
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody($other))->assertCreated();

    $this->actingAs($this->user)->post('/logout', ['push_endpoint' => $mine])->assertRedirect();

    $hashes = PushSubscription::query()->pluck('endpoint_hash')->all();
    expect($hashes)->toBe([PushSubscription::hashEndpoint($other)]);
});

it('does not let a logout delete another user\'s subscription', function () {
    $victim = User::factory()->withPreset(Preset::Child)->create();
    $this->actingAs($victim)->postJson('/settings/notifications/push', subBody('https://fcm.googleapis.com/fcm/send/v'))->assertCreated();

    $this->actingAs($this->user)->post('/logout', ['push_endpoint' => 'https://fcm.googleapis.com/fcm/send/v']);

    expect(PushSubscription::query()->count())->toBe(1);
});

it('test endpoint only tries the newest few devices', function () {
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $keys = VAPID::createVapidKeys();
    config(['push.subject' => 'mailto:test@example.test', 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey']]);
    $fake = new FakePushSender;
    app()->instance(PushSender::class, $fake);
    foreach (range(1, 5) as $i) {
        $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody("https://fcm.googleapis.com/fcm/send/d{$i}"))->assertCreated();
    }

    $this->actingAs($this->user)->postJson('/settings/notifications/push/test')->assertOk()->assertExactJson(['sent' => 3]);
    expect($fake->sent)->toHaveCount(3);
});

it('re-registering the same endpoint keeps one row', function () {
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody())->assertCreated();
    $this->actingAs($this->user)->postJson('/settings/notifications/push', subBody())->assertCreated();

    expect(PushSubscription::query()->count())->toBe(1);
});
