<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

function inviteAs(User $admin, array $data = []): TestResponse
{
    return test()->actingAs($admin)->post('/settings/invitations', array_merge(
        ['email' => 'Eva@Example.test', 'preset' => 'child', 'locale' => 'sk'], $data,
    ));
}

function flashedToken(TestResponse $response): string
{
    $link = $response->getSession()->get('flash_data.invite.link');
    expect($link)->toContain('/invite/');

    return substr($link, strrpos($link, '/') + 1);
}

it('creates an invitation with a hashed token, a 7 day expiry, a queued mail and an audit row', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    $response = inviteAs($admin)->assertRedirect()->assertSessionHas('flash_data.invite.mailQueued', true);
    $token = flashedToken($response);

    expect($token)->toHaveLength(40);
    $inv = Invitation::sole();
    expect($inv->email)->toBe('eva@example.test')
        ->and($inv->token_hash)->toHaveLength(64)->toBe(hash('sha256', $token))
        ->and($inv->preset)->toBe(Preset::Child)
        ->and($inv->locale)->toBe('sk')
        ->and($inv->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue()
        ->and($inv->invited_by)->toBe($admin->id);

    Notification::assertSentOnDemand(InviteNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'eva@example.test');

    $audit = AuditEntry::where('event', 'invite_created')->sole();
    expect($audit->meta)->toBe(['email' => 'eva@example.test', 'preset' => 'child']);
});

it('stores the plain token nowhere in the database', function () {
    Notification::fake();
    $token = flashedToken(inviteAs(User::factory()->admin()->create()));

    foreach (['invitations', 'audit_logs'] as $table) {
        expect(json_encode(DB::table($table)->get()))->not->toContain($token);
    }
});

it('refuses an email that already belongs to a member and invalid input', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email' => 'eva@example.test']);

    inviteAs($admin)->assertSessionHasErrors(['email' => 'invite.dialog.error.exists']);
    inviteAs($admin, ['email' => 'not-an-email'])->assertSessionHasErrors(['email' => 'auth.error.email_invalid']);
    inviteAs($admin, ['email' => 'x@example.test', 'preset' => 'admin'])->assertSessionHasErrors('preset');
    inviteAs($admin, ['email' => 'x@example.test', 'locale' => 'de'])->assertSessionHasErrors('locale');

    expect(Invitation::count())->toBe(0);
    Notification::assertNothingSent();
});

it('keeps exactly one open invitation per email', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    $first = flashedToken(inviteAs($admin));
    $second = flashedToken(inviteAs($admin, ['email' => 'EVA@example.test']));

    expect(Invitation::count())->toBe(2)
        ->and(Invitation::whereNull('revoked_at')->count())->toBe(1)
        ->and(Invitation::findByToken($first)->state())->toBe('revoked')
        ->and(Invitation::findByToken($second)->state())->toBe('valid')
        ->and(AuditEntry::where('event', 'invite_revoked')->count())->toBe(1)
        ->and(AuditEntry::where('event', 'invite_created')->count())->toBe(2);
});

it('resend rotates the token, resets expiry, clears the mail failure, queues and audits', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $old = flashedToken(inviteAs($admin));
    $inv = Invitation::sole();
    $inv->forceFill(['mail_failed_at' => now(), 'expires_at' => now()->addDay()])->save();

    $response = test()->actingAs($admin)->post("/settings/invitations/{$inv->id}/resend")
        ->assertSessionHas('flash_data.invite.rotated', true);
    $new = flashedToken($response);

    $inv->refresh();
    expect($new)->not->toBe($old)
        ->and(Invitation::findByToken($old))->toBeNull()
        ->and(Invitation::findByToken($new)->id)->toBe($inv->id)
        ->and($inv->mail_failed_at)->toBeNull()
        ->and($inv->expires_at->greaterThan(now()->addDays(6)))->toBeTrue()
        ->and(AuditEntry::where('event', 'invite_resent')->count())->toBe(1);
    Notification::assertSentOnDemandTimes(InviteNotification::class, 2);
});

it('copy link rotates the token without sending mail', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $old = flashedToken(inviteAs($admin));
    $inv = Invitation::sole();

    $response = test()->actingAs($admin)->post("/settings/invitations/{$inv->id}/link")
        ->assertSessionHas('flash_data.invite.mailQueued', false);
    $new = flashedToken($response);

    expect(Invitation::findByToken($old))->toBeNull()
        ->and(Invitation::findByToken($new)->id)->toBe($inv->id)
        ->and(AuditEntry::where('event', 'invite_resent')->count())->toBe(1);
    Notification::assertSentOnDemandTimes(InviteNotification::class, 1);
});

it('revokes and audits, and a revoked or accepted invitation cannot change', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $token = flashedToken(inviteAs($admin));
    $inv = Invitation::sole();

    test()->actingAs($admin)->delete("/settings/invitations/{$inv->id}")->assertRedirect();
    expect($inv->refresh()->state())->toBe('revoked')
        ->and(AuditEntry::where('event', 'invite_revoked')->count())->toBe(1);

    $hash = $inv->token_hash;
    test()->actingAs($admin)->post("/settings/invitations/{$inv->id}/resend")->assertStatus(409);
    test()->actingAs($admin)->post("/settings/invitations/{$inv->id}/link")->assertStatus(409);
    test()->actingAs($admin)->delete("/settings/invitations/{$inv->id}")->assertStatus(409);
    expect($inv->refresh()->token_hash)->toBe($hash)
        ->and(Invitation::findByToken($token)->state())->toBe('revoked');

    $accepted = Invitation::create([
        'email' => 'done@example.test', 'token_hash' => hash('sha256', 'x'), 'preset' => 'guest', 'locale' => 'en',
        'expires_at' => now()->addDays(3), 'accepted_at' => now(),
    ]);
    test()->actingAs($admin)->post("/settings/invitations/{$accepted->id}/resend")->assertStatus(409);
    expect($accepted->refresh()->token_hash)->toBe(hash('sha256', 'x'));
});

it('reports the state of an expired invitation and still allows resend', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    flashedToken(inviteAs($admin));
    $inv = Invitation::sole();
    $inv->forceFill(['expires_at' => now()->subMinute()])->save();
    expect($inv->state())->toBe('expired');

    test()->actingAs($admin)->post("/settings/invitations/{$inv->id}/resend")->assertRedirect();
    expect($inv->refresh()->state())->toBe('valid');
});

it('returns 404 for an unknown invitation id as admin', function () {
    test()->actingAs(User::factory()->admin()->create())->post('/settings/invitations/99999/resend')->assertNotFound();
});

it('takes a per-email advisory lock so concurrent creates cannot race the open-invite index', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    inviteAs($admin)->assertRedirect();

    // The test transaction keeps the xact-level lock visible; a real request releases it on commit.
    $held = DB::selectOne("select count(*) as c from pg_locks where locktype = 'advisory' and pid = pg_backend_pid() and granted")->c;
    expect($held)->toBeGreaterThanOrEqual(1);
});
