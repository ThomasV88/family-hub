<?php

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Minishlink\WebPush\VAPID;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['push.subject' => null, 'push.public_key' => null, 'push.private_key' => null]);
});

it('renders defaults for a member without rows', function () {
    $user = User::factory()->create(['email' => 'me@example.test']);

    $this->actingAs($user)->get('/settings/notifications')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Notifications')
        ->where('email', 'me@example.test')
        ->where('preferences', ['mail' => true, 'push' => false])
        ->where('vapidPublicKey', null)
        ->has('sections'));
});

it('exposes only the public key when push is configured', function () {
    $keys = VAPID::createVapidKeys();
    config(['push.subject' => 'mailto:a@example.test', 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey']]);

    $response = $this->actingAs(User::factory()->create())->get('/settings/notifications')->assertOk();
    $response->assertInertia(fn (Assert $p) => $p->where('vapidPublicKey', $keys['publicKey']));
    expect($response->getContent())->not->toContain($keys['privateKey']);
});

it('stores the member own channel preferences', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->put('/settings/notifications/push', ['enabled' => true])->assertRedirect();
    $this->actingAs($user)->put('/settings/notifications/mail', ['enabled' => false])->assertStatus(303);

    $prefs = app(NotificationPreferences::class);
    expect($prefs->enabled($user, 'push'))->toBeTrue()
        ->and($prefs->enabled($user, 'mail'))->toBeFalse()
        ->and($prefs->enabled($other, 'mail'))->toBeTrue()
        ->and($prefs->enabled($other, 'push'))->toBeFalse()
        ->and(NotificationPreference::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('404s unknown channels and validates the body', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/settings/notifications/sms', ['enabled' => true])->assertNotFound();
    $this->actingAs($user)->put('/settings/notifications/push', [])->assertSessionHasErrors('enabled');
    $this->actingAs($user)->put('/settings/notifications/push', ['enabled' => 'maybe'])->assertSessionHasErrors('enabled');
});

it('lists sections in order for admins', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/settings/notifications')->assertInertia(fn (Assert $p) => $p
        ->where('sections.0.key', 'members')
        ->where('sections.1.key', 'audit')
        ->where('sections.2.key', 'finance_accounts')
        ->where('sections.3.key', 'ai_calls')
        ->where('sections.4.key', 'ai_agents')
        ->where('sections.5.key', 'notifications')
        ->where('sections.6.key', 'security')
        ->where('sections.7.key', 'appearance'));
});
