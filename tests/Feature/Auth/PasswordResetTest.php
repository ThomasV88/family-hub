<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => RateLimiter::clear('x'));

it('gives identical responses for a known and an unknown email and queues mail only for the known one', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'anna@example.test']);

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'anna@example.test']);
    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);

    foreach ([$known, $unknown] as $response) {
        $response->assertRedirect('/forgot-password')->assertSessionHas('status', 'reset_link_sent')->assertSessionHasNoErrors();
    }
    expect($known->getStatusCode())->toBe($unknown->getStatusCode());
    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    Notification::assertCount(1);
});

it('queues the notification and builds the localized mail with the reset url', function () {
    expect(new ResetPasswordNotification('tok'))->toBeInstanceOf(ShouldQueue::class);

    $user = User::factory()->create(['email' => 'anna@example.test', 'locale' => 'sk']);
    $mail = (new ResetPasswordNotification('tok123'))->toMail($user);

    expect($mail->subject)->toBe(trans('mail.reset_subject', [], 'sk'))
        ->and($mail->actionUrl)->toContain('/reset-password/tok123?email=anna%40example.test')
        ->and($mail->actionText)->toBe(trans('mail.reset_action', [], 'sk'));
});

it('renders the reset page as valid only for a real token', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);
    $token = Password::broker()->createToken($user);

    $this->get("/reset-password/$token?email=anna@example.test")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Auth/ResetPassword')->where('valid', true)->where('token', $token));
    $this->get('/reset-password/wrong?email=anna@example.test')->assertOk()->assertInertia(fn (Assert $p) => $p->where('valid', false));
    $this->get("/reset-password/$token?email=nobody@example.test")->assertOk()->assertInertia(fn (Assert $p) => $p->where('valid', false));
    $this->get("/reset-password/$token")->assertOk()->assertInertia(fn (Assert $p) => $p->where('valid', false));
});

it('sends no-referrer on the reset page', function () {
    $this->get('/reset-password/abc?email=a@example.test')->assertHeader('Referrer-Policy', 'no-referrer');
});

it('changes the password, ends other sessions, rotates the remember token and goes to login', function () {
    $user = User::factory()->create(['email' => 'anna@example.test', 'remember_token' => 'old-token']);
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token, 'email' => 'anna@example.test',
        'password' => 'a long new passphrase', 'password_confirmation' => 'a long new passphrase',
    ])->assertRedirect('/login')->assertSessionHas('status', 'password_reset');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe('old-token')
        ->and(Hash::check('a long new passphrase', $user->fresh()->password))->toBeTrue();
});

it('maps a short password and a mismatch to the invite error keys', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);
    $token = Password::broker()->createToken($user);
    $base = ['token' => $token, 'email' => 'anna@example.test'];

    $this->from('/reset-password/'.$token)->post('/reset-password', $base + ['password' => 'short-11-ch', 'password_confirmation' => 'short-11-ch'])
        ->assertSessionHasErrors(['password' => 'invite.accept.error.password_short']);
    $this->from('/reset-password/'.$token)->post('/reset-password', $base + ['password' => 'a long new passphrase', 'password_confirmation' => 'different long phrase'])
        ->assertSessionHasErrors(['password' => 'invite.accept.error.password_mismatch']);
});

it('rejects an expired token with the neutral invalid key', function () {
    $user = User::factory()->create(['email' => 'anna@example.test']);
    $token = Password::broker()->createToken($user);

    $this->travel(61)->minutes();

    $this->from('/reset-password/'.$token)->post('/reset-password', [
        'token' => $token, 'email' => 'anna@example.test',
        'password' => 'a long new passphrase', 'password_confirmation' => 'a long new passphrase',
    ])->assertSessionHasErrors(['email' => 'auth.reset.invalid_body']);
});

it('throttles the 6th forgot-password request per IP within a minute', function () {
    Notification::fake();
    foreach (range(1, 5) as $i) {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => "u$i@example.test"])->assertSessionHasNoErrors();
    }

    $this->from('/forgot-password')->post('/forgot-password', ['email' => 'u6@example.test'])
        ->assertRedirect('/forgot-password')->assertSessionHasErrors('throttle');
});
