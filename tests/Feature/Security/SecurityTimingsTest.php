<?php

use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\User;
use App\Security\AppSettings;
use App\Security\SecurityTimings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function stSet(string $key, mixed $value): void
{
    DB::table('app_settings')->upsert([['key' => $key, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]], ['key'], ['value']);
}

function stTimings(): SecurityTimings
{
    // A fresh instance with a fresh memo: the stored value is read again.
    return new SecurityTimings(new AppSettings);
}

it('defaults both windows to one hour', function () {
    expect(config('security.idle_seconds'))->toBe(3600)
        ->and(config('security.confirm_window_seconds'))->toBe(3600)
        ->and(stTimings()->idle())->toBe(3600)
        ->and(stTimings()->confirmWindow())->toBe(3600);
});

it('uses the admin value over the config default and clamps it', function () {
    stSet(SecurityTimings::IDLE, 7200);
    stSet(SecurityTimings::CONFIRM, 1800);
    expect(stTimings()->idle())->toBe(7200)->and(stTimings()->confirmWindow())->toBe(1800);

    stSet(SecurityTimings::IDLE, 5);
    stSet(SecurityTimings::CONFIRM, 999999);
    expect(stTimings()->idle())->toBe(60)->and(stTimings()->confirmWindow())->toBe(3600);
});

it('falls back to the config default for a missing or malformed stored value', function () {
    config(['security.idle_seconds' => 900, 'security.confirm_window_seconds' => 600]);
    expect(stTimings()->idle())->toBe(900);

    foreach (['soon', '15m', 0, -5, 1.5, true, [], null] as $bad) {
        stSet(SecurityTimings::IDLE, $bad);
        stSet(SecurityTimings::CONFIRM, $bad);
        expect(stTimings()->idle())->toBe(900, var_export($bad, true))
            ->and(stTimings()->confirmWindow())->toBe(600, var_export($bad, true));
    }
});

it('gates on the stored value in the idle gate and the recent rule', function () {
    $this->useRealSensitiveSession();
    stSet(SecurityTimings::IDLE, 120);
    stSet(SecurityTimings::CONFIRM, 120);
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $at = now()->subSeconds(100)->getTimestamp();

    $this->actingAs($user)->withSession(['sensitive.active_at' => $at])->get('/finance')->assertOk();
    $old = now()->subSeconds(130)->getTimestamp();
    $this->actingAs($user)->withSession(['sensitive.active_at' => $old])->get('/finance')->assertRedirect('/confirm-access');
});

it('lets an admin save both windows, audited with the setting name and number only', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->putJson('/settings/security/timings', ['idle_seconds' => 5400, 'confirm_window_seconds' => 900])
        ->assertOk()->assertJson(['idle_seconds' => 5400, 'confirm_window_seconds' => 900]);

    expect(stTimings()->idle())->toBe(5400)->and(stTimings()->confirmWindow())->toBe(900);
    $rows = AuditEntry::where('event', 'security_policy_changed')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->meta)->toEqual(['setting' => 'security.idle_seconds', 'value' => 5400])
        ->and($rows[1]->meta)->toEqual(['setting' => 'security.confirm_window_seconds', 'value' => 900]);
});

it('audits only the value that changed', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->putJson('/settings/security/timings', ['idle_seconds' => 3600, 'confirm_window_seconds' => 1200])->assertOk();
    $rows = AuditEntry::where('event', 'security_policy_changed')->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->meta['setting'])->toBe('security.confirm_window_seconds');
});

it('rejects out of range, non-integer and missing values and saves nothing', function () {
    $admin = User::factory()->admin()->create();
    $cases = [
        ['idle_seconds' => 59, 'confirm_window_seconds' => 600],
        ['idle_seconds' => 86401, 'confirm_window_seconds' => 600],
        ['idle_seconds' => 3600, 'confirm_window_seconds' => 3601],
        ['idle_seconds' => 3600, 'confirm_window_seconds' => 59],
        ['idle_seconds' => '3600', 'confirm_window_seconds' => 600],
        ['idle_seconds' => 'x', 'confirm_window_seconds' => 600],
        ['idle_seconds' => 3600],
        [],
    ];
    foreach ($cases as $body) {
        $this->actingAs($admin)->putJson('/settings/security/timings', $body)->assertStatus(422);
    }
    expect(DB::table('app_settings')->where('key', 'like', 'security.%')->count())->toBe(0)
        ->and(AuditEntry::where('event', 'security_policy_changed')->count())->toBe(0);
});

it('is admin only, and refuses a stale confirmation without saving', function () {
    $this->useRealSensitiveSession();
    $body = ['idle_seconds' => 7200, 'confirm_window_seconds' => 900];

    $this->actingAs(User::factory()->withPreset(Preset::Parent)->create())->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->putJson('/settings/security/timings', $body)->assertForbidden();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->subMinutes(61)->getTimestamp()])
        ->putJson('/settings/security/timings', $body)->assertStatus(423)->assertJson(['error' => 'confirm_required']);
    expect(DB::table('app_settings')->where('key', 'like', 'security.%')->count())->toBe(0);

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->putJson('/settings/security/timings', $body)->assertOk();
});
