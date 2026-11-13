<?php

use App\Access\Preset;
use App\Auth\InvalidInviteException;
use App\Auth\InviteService;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

const GOOD_PASSWORD = 'correct horse battery staple';

function openInvite(string $token = 'plaintoken1234', array $attrs = []): Invitation
{
    return Invitation::create(array_merge([
        'email' => 'eva@example.test', 'token_hash' => hash('sha256', $token), 'preset' => 'child', 'locale' => 'sk',
        'expires_at' => now()->addDays(7),
    ], $attrs));
}

function acceptData(array $over = []): array
{
    return array_merge(['name' => 'Eva', 'password' => GOOD_PASSWORD, 'password_confirmation' => GOOD_PASSWORD], $over);
}

it('shows the valid state with the email and no-referrer', function () {
    openInvite();
    $this->withCookie('locale', 'sk')->get('/invite/plaintoken1234')
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn ($page) => $page->component('Auth/AcceptInvite')
            ->where('state', 'valid')->where('email', 'eva@example.test')->where('token', 'plaintoken1234')->where('googleEnabled', false));
});

it('sets the invitation locale cookie and redirects once when the visitor has no locale cookie', function () {
    openInvite();
    $this->get('/invite/plaintoken1234')->assertRedirect('/invite/plaintoken1234')->assertCookie('locale', 'sk');
});

it('reports expired, used, revoked and unknown states without leaking the email', function () {
    openInvite('expiredtok', ['email' => 'a@example.test', 'expires_at' => now()->subMinute()]);
    openInvite('usedtok', ['email' => 'b@example.test', 'accepted_at' => now()]);
    openInvite('revokedtok', ['email' => 'c@example.test', 'revoked_at' => now()]);

    foreach (['expiredtok' => 'expired', 'usedtok' => 'used', 'revokedtok' => 'revoked', 'nosuchtoken' => 'revoked'] as $token => $state) {
        $response = $this->withCookie('locale', 'en')->get("/invite/$token")->assertOk()
            ->assertInertia(fn ($page) => $page->where('state', $state)->where('email', null)->where('token', null));
        expect($response->getContent())->not->toContain('@example.test');
    }
});

it('creates the account with the preset grants, signs in, and the link cannot be reused', function () {
    openInvite();

    $this->withCookie('locale', 'sk')->post('/invite/plaintoken1234', acceptData())
        ->assertRedirect('/')
        ->assertSessionHas('toast.key', 'invite.accept.welcome');

    $user = User::sole();
    expect($user->email)->toBe('eva@example.test')
        ->and($user->locale)->toBe('sk')
        ->and($user->is_admin)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->google_id)->toBeNull();
    $this->assertAuthenticatedAs($user);

    $levels = $user->modulePermissions()->get()->mapWithKeys(fn ($p) => [$p->module->value => $p->level])->all();
    expect($levels)->toEqual(Preset::Child->grants());

    $inv = Invitation::sole();
    expect($inv->accepted_at)->not->toBeNull()->and($inv->accepted_user_id)->toBe($user->id);
    expect(AuditEntry::where('event', 'invite_accepted')->sole()->actor_id)->toBe($user->id);

    auth()->logout();
    $this->flushSession();
    $this->withCookie('locale', 'en')->post('/invite/plaintoken1234', acceptData(['name' => 'Other']))->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'used'));
    expect(User::count())->toBe(1);
});

it('validates name and password with i18n keys', function () {
    openInvite();
    $this->post('/invite/plaintoken1234', acceptData(['name' => '']))->assertSessionHasErrors(['name' => 'invite.accept.error.name_required']);
    $this->post('/invite/plaintoken1234', acceptData(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertSessionHasErrors(['password' => 'invite.accept.error.password_short']);
    $this->post('/invite/plaintoken1234', acceptData(['password_confirmation' => 'different one entirely']))
        ->assertSessionHasErrors(['password' => 'invite.accept.error.password_mismatch']);
    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('does not create a user for a non-valid token', function () {
    openInvite('expiredtok', ['expires_at' => now()->subMinute()]);
    $this->withCookie('locale', 'en')->post('/invite/expiredtok', acceptData())->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'expired'));
    expect(User::count())->toBe(0);
});

it('accepts an invitation only once even when attempted twice on the same row', function () {
    $inv = openInvite();
    $service = app(InviteService::class);

    $service->accept($inv, ['name' => 'Eva', 'password' => GOOD_PASSWORD]);
    // The second caller still holds the stale, apparently valid model: the row lock and re-check must refuse it.
    expect(fn () => $service->accept($inv, ['name' => 'Eva2', 'password' => GOOD_PASSWORD]))->toThrow(InvalidInviteException::class);

    expect(User::count())->toBe(1)->and(DB::table('module_permissions')->count())->toBe(4);
});

it('refuses to accept when the email already has an account', function () {
    $inv = openInvite();
    User::factory()->create(['email' => 'EVA@example.test'.'']);
    expect(fn () => app(InviteService::class)->accept($inv, ['name' => 'Eva', 'password' => GOOD_PASSWORD]))->toThrow(InvalidInviteException::class);
});

it('keeps registration closed and sends signed-in users home', function () {
    $this->get('/register')->assertNotFound();
    openInvite();
    $this->actingAs(User::factory()->create())->get('/invite/plaintoken1234')->assertRedirect('/');
});

it('never writes the plain token to the database', function () {
    openInvite();
    $this->withCookie('locale', 'sk')->post('/invite/plaintoken1234', acceptData());
    foreach (['invitations', 'audit_logs', 'users'] as $table) {
        expect(json_encode(DB::table($table)->get()))->not->toContain('plaintoken1234');
    }
});
