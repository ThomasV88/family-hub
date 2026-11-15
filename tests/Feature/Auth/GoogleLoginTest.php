<?php

use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\GoogleLinkedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeGoogle;

function googleInvite(string $email = 'eva@example.test', string $token = 'gtoken1234'): Invitation
{
    return Invitation::create([
        'email' => $email, 'token_hash' => hash('sha256', $token), 'preset' => 'child', 'locale' => 'sk', 'expires_at' => now()->addDays(7),
    ]);
}

beforeEach(fn () => FakeGoogle::configure());

it('hides Google until credentials are configured and sends redirects to login without them', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
    $this->get('/login')->assertInertia(fn ($p) => $p->where('googleEnabled', false));
    $this->get('/auth/google/redirect')->assertRedirect('/login')->assertSessionHas('status', 'google_failed');
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('status', 'google_failed');

    FakeGoogle::configure();
    $this->get('/login')->assertInertia(fn ($p) => $p->where('googleEnabled', true));
});

it('redirects to Google and keeps the invite token server-side only', function () {
    FakeGoogle::returns('sub-1', 'eva@example.test');
    googleInvite();

    $response = $this->get('/auth/google/redirect?invite=gtoken1234');
    $response->assertRedirect('https://accounts.google.test/auth')->assertSessionHas('google_invite_token', 'gtoken1234')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Location'))->not->toContain('gtoken1234');
});

it('sends an invalid invite token to the invite page instead of Google', function () {
    FakeGoogle::returns('sub-1', 'eva@example.test');
    $this->get('/auth/google/redirect?invite=nope')->assertRedirect('/invite/nope');
    googleInvite('x@example.test', 'oldtok');
    Invitation::query()->update(['revoked_at' => now()]);
    $this->get('/auth/google/redirect?invite=oldtok')->assertRedirect('/invite/oldtok');
});

it('creates the account from a valid invite when Google verifies the invited email', function () {
    $inv = googleInvite();
    FakeGoogle::returns('sub-1', 'Eva@Example.test');

    $this->withSession(['google_invite_token' => 'gtoken1234'])->get('/auth/google/callback')
        ->assertRedirect('/')->assertSessionHas('toast.key', 'invite.accept.welcome')->assertSessionMissing('google_invite_token');

    $user = User::sole();
    expect($user->google_id)->toBe('sub-1')->and($user->email)->toBe('eva@example.test')->and($user->locale)->toBe('sk')
        ->and($user->is_admin)->toBeFalse()->and($user->modulePermissions()->count())->toBe(4);
    $this->assertAuthenticatedAs($user);
    expect($inv->refresh()->accepted_user_id)->toBe($user->id);
    expect(AuditEntry::where('event', 'invite_accepted')->count())->toBe(1);
});

it('refuses a Google account with a different email and keeps the invite valid', function () {
    $inv = googleInvite();
    FakeGoogle::returns('sub-9', 'someone.else@example.test');

    $this->withSession(['google_invite_token' => 'gtoken1234'])->get('/auth/google/callback')
        ->assertRedirect('/invite/gtoken1234')->assertSessionHas('status', 'google_mismatch');

    expect(User::count())->toBe(0)->and($inv->refresh()->state())->toBe('valid');
    $this->assertGuest();
});

it('signs in an existing member matched by Google id', function () {
    $user = User::factory()->create(['email' => 'anna@example.test', 'google_id' => 'sub-7']);
    // Google changed the email: the stable subject still matches.
    FakeGoogle::returns('sub-7', 'anna.new@gmail.test');

    $this->get('/auth/google/callback')->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(AuditEntry::where('event', 'login_success')->count())->toBe(1);
});

it('links a Google id once by verified email and audits it', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'anna@example.test']);
    FakeGoogle::returns('sub-7', 'ANNA@example.test');

    $this->get('/auth/google/callback')->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->google_id)->toBe('sub-7')->and(AuditEntry::where('event', 'google_linked')->count())->toBe(1);
    Notification::assertSentToTimes($user, GoogleLinkedNotification::class, 1);
});

it('builds a queued, localized link notice for the account owner', function () {
    expect(new GoogleLinkedNotification)->toBeInstanceOf(ShouldQueue::class);

    $user = User::factory()->create(['locale' => 'pl']);
    $mail = (new GoogleLinkedNotification)->toMail($user);

    expect($mail->subject)->toBe(trans('mail.google_linked_subject', [], 'pl'))->and($mail->subject)->not->toStartWith('mail.');
});

it('does not notify on a plain sign-in by Google id', function () {
    Notification::fake();
    User::factory()->create(['email' => 'anna@example.test', 'google_id' => 'sub-7']);
    FakeGoogle::returns('sub-7', 'anna@example.test');

    $this->get('/auth/google/callback')->assertRedirect('/');
    Notification::assertNothingSent();
});

it('refuses the same email with a different Google id', function () {
    User::factory()->create(['email' => 'anna@example.test', 'google_id' => 'sub-7']);
    FakeGoogle::returns('sub-8', 'anna@example.test');

    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('status', 'google_failed');
    $this->assertGuest();
});

it('never creates an account from the login page, even with a pending invite for that email', function () {
    googleInvite('newbie@example.test');
    FakeGoogle::returns('sub-5', 'newbie@example.test');

    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('status', 'google_not_invited');
    expect(User::count())->toBe(0)->and(Invitation::sole()->accepted_at)->toBeNull();
    $this->assertGuest();
});

it('rejects unverified or missing email_verified in every branch', function (string $branch, bool|string|null $verified) {
    $anna = User::factory()->create(['email' => 'anna@example.test', 'google_id' => $branch === 'sub' ? 'sub-7' : null]);
    googleInvite('eva@example.test');
    $email = match ($branch) {
        'invite' => 'eva@example.test',
        default => 'anna@example.test',
    };
    FakeGoogle::returns('sub-7', $email, $verified);

    $session = $branch === 'invite' ? ['google_invite_token' => 'gtoken1234'] : [];
    $this->withSession($session)->get('/auth/google/callback')->assertRedirect(
        '/login',
    )->assertSessionHas('status', 'google_failed');
    $this->assertGuest();
    expect(User::count())->toBe(1)->and($anna->refresh()->google_id)->toBe($branch === 'sub' ? 'sub-7' : null);
})->with([
    'invite false' => ['invite', false],
    'invite missing' => ['invite', null],
    'sub false' => ['sub', false],
    'email false' => ['email', false],
    'email missing' => ['email', null],
    'email string false' => ['email', 'false'],
]);

it('treats a Socialite exception as google_failed', function () {
    FakeGoogle::throws();
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('status', 'google_failed');
});

it('fails when the invite was revoked during the Google round trip', function () {
    googleInvite();
    Invitation::query()->update(['revoked_at' => now()]);
    FakeGoogle::returns('sub-1', 'eva@example.test');

    $this->withSession(['google_invite_token' => 'gtoken1234'])->get('/auth/google/callback')
        ->assertRedirect('/login')->assertSessionHas('status', 'google_failed');
    expect(User::count())->toBe(0);
});

it('regenerates the session id and does not remember the login', function () {
    User::factory()->create(['email' => 'anna@example.test', 'google_id' => 'sub-7']);
    FakeGoogle::returns('sub-7', 'anna@example.test');

    $this->startSession();
    $before = session()->getId();
    $response = $this->get('/auth/google/callback');
    expect(session()->getId())->not->toBe($before);
    expect(collect($response->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')))->toBeFalse();
});

it('persists no Google token anywhere', function () {
    googleInvite();
    FakeGoogle::returns('sub-1', 'eva@example.test');
    $this->withSession(['google_invite_token' => 'gtoken1234'])->get('/auth/google/callback');

    foreach (['users', 'sessions', 'audit_logs', 'invitations'] as $table) {
        $json = json_encode(DB::table($table)->get());
        expect($json)->not->toContain(FakeGoogle::TOKEN)->and($json)->not->toContain('fake-refresh-token-xyz');
    }
});

it('redirects signed-in members away from the Google routes', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/auth/google/redirect')->assertRedirect('/');
    $this->get('/auth/google/callback')->assertRedirect('/');
});
