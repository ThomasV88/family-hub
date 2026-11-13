<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const REV_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

function revRow(?User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user?->id, 'ip_address' => '203.0.113.5', 'user_agent' => REV_UA,
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp,
    ]);
}

it('revokes one session, rotates the remember token and audits the device', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['remember_token' => 'old-token']);
    $id = str_repeat('a', 40);
    revRow($member, $id);
    revRow($member, str_repeat('b', 40));

    $this->actingAs($admin)->delete("/settings/members/{$member->id}/sessions/{$id}")
        ->assertRedirect()->assertSessionHas('toast', ['key' => 'sessions.revoked_toast']);

    expect(DB::table('sessions')->where('id', $id)->exists())->toBeFalse();
    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(1);
    expect($member->fresh()->remember_token)->not->toBe('old-token');
    $audit = DB::table('audit_logs')->where('event', 'session_revoked')->first();
    expect($audit->target_user_id)->toBe($member->id);
    expect(json_decode($audit->meta, true))->toBe(['device' => 'Safari on iOS']);
});

it('returns 404 and deletes nothing for a session of another user', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $other = User::factory()->create();
    $id = str_repeat('c', 40);
    revRow($other, $id);

    $this->actingAs($admin)->delete("/settings/members/{$member->id}/sessions/{$id}")->assertNotFound();

    expect(DB::table('sessions')->where('id', $id)->exists())->toBeTrue();
    expect(DB::table('audit_logs')->where('event', 'session_revoked')->count())->toBe(0);
});

it('revokes all sessions of a member and audits it', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['remember_token' => 'old-token']);
    $other = User::factory()->create();
    revRow($member, str_repeat('a', 40));
    revRow($member, str_repeat('b', 40));
    revRow($other, str_repeat('c', 40));

    $this->actingAs($admin)->delete("/settings/members/{$member->id}/sessions")->assertRedirect();

    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);
    expect($member->fresh()->remember_token)->not->toBe('old-token');
    expect(DB::table('audit_logs')->where('event', 'session_revoked_all')->where('target_user_id', $member->id)->count())->toBe(1);
});

it('does not let the old remember cookie bring a revoked member back', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['remember_token' => 'old-token']);
    revRow($member, str_repeat('a', 40));
    $recaller = $member->id.'|old-token|'.$member->getAuthPassword();
    $name = Auth::guard('web')->getRecallerName();

    // Before the revoke the cookie alone signs the member in.
    $this->withCookie($name, $recaller)->get('/')->assertOk();
    Auth::forgetGuards();
    $this->flushSession();

    $this->actingAs($admin)->delete("/settings/members/{$member->id}/sessions")->assertRedirect();
    Auth::forgetGuards();
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $this->app['session']->flush();

    $this->withCookie($name, $recaller)->get('/')->assertRedirect('/login');
});

it('rolls the revoke back when the audit row cannot be written', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['remember_token' => 'old-token']);
    $id = str_repeat('a', 40);
    revRow($member, $id);
    $this->mock(\App\Audit\AuditLogger::class)->shouldReceive('record')->andThrow(new RuntimeException('audit down'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->delete("/settings/members/{$member->id}/sessions/{$id}"))->toThrow(RuntimeException::class);

    expect(DB::table('sessions')->where('id', $id)->exists())->toBeTrue();
    expect($member->fresh()->remember_token)->toBe('old-token');
});
