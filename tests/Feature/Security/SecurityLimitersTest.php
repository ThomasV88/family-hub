<?php

use App\Audit\AuditEvent;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    RateLimiter::clear('x');
    Route::middleware('web')->group(function () {
        Route::post('/test-probe/reauth', fn () => response()->json(['ok' => true]))->middleware(['access:authenticated', 'throttle:reauth']);
        Route::post('/test-probe/2fa', fn () => response()->json(['ok' => true]))->middleware(['access:public', 'throttle:two-factor']);
    });
});

it('limits reauth per member and audits one lockout', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($a)->postJson('/test-probe/reauth')->assertOk();
    }
    $r = $this->actingAs($a)->postJson('/test-probe/reauth')->assertStatus(429)->assertJsonPath('error', 'throttle');
    expect($r->json('minutes'))->toBeGreaterThanOrEqual(1);

    $this->actingAs($b)->postJson('/test-probe/reauth')->assertOk();

    $rows = AuditEntry::where('event', AuditEvent::Lockout->value)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->actor_id)->toBe($a->id);
});

it('answers form posts with a redirect and errors.throttle', function () {
    $a = User::factory()->create();
    foreach (range(1, 5) as $i) {
        $this->actingAs($a)->from('/somewhere')->post('/test-probe/reauth')->assertOk();
    }
    $this->actingAs($a)->from('/somewhere')->post('/test-probe/reauth')
        ->assertRedirect('/somewhere')->assertSessionHasErrors('throttle');
});

it('keys the challenge limiter by user id, not session', function () {
    $u = User::factory()->create();
    foreach (range(1, 5) as $i) {
        $this->withSession(['login.id' => $u->id])->postJson('/test-probe/2fa')->assertOk();
    }
    $this->flushSession();
    $this->withSession(['login.id' => $u->id])->postJson('/test-probe/2fa')->assertStatus(429);

    $other = User::factory()->create();
    $this->flushSession();
    $this->withSession(['login.id' => $other->id])->postJson('/test-probe/2fa')->assertOk();
});
