<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/test-probe/recent', fn () => 'ok')->middleware('access:recent');
        Route::get('/test-probe/admin-recent', fn () => 'ok')->middleware(['access:admin', 'access:recent']);
    });
});

it('sends guests to login', function () {
    $this->get('/test-probe/recent')->assertRedirect('/login');
});

it('refuses a stale proof with 423 for JSON and a redirect for browsers, without auditing', function () {
    $this->useRealSensitiveSession();
    $user = User::factory()->create();
    $before = AuditEntry::count();

    $this->actingAs($user)->getJson('/test-probe/recent')->assertStatus(423)->assertExactJson(['error' => 'confirm_required']);
    $this->actingAs($user)->get('/test-probe/recent')->assertRedirect('/confirm-access')
        ->assertSessionHas('url.intended', '/test-probe/recent');
    $this->actingAs($user)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) \Inertia\Inertia::getVersion()])->getJson('/test-probe/recent')->assertRedirect('/confirm-access');

    expect(AuditEntry::count())->toBe($before);
});

it('honours the 10 minute window', function () {
    $this->useRealSensitiveSession();
    $user = User::factory()->create();
    $at = now()->subMinutes(5)->getTimestamp();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => $at])->get('/test-probe/recent')->assertOk();

    $stale = now()->subMinutes(11)->getTimestamp();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => $stale])->getJson('/test-probe/recent')->assertStatus(423);
});

it('stacks with another rule', function () {
    $this->useRealSensitiveSession();
    $this->actingAs(User::factory()->create())->getJson('/test-probe/admin-recent')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->getJson('/test-probe/admin-recent')->assertStatus(423);
});

it('passes with the default always-fresh seam', function () {
    $this->actingAs(User::factory()->create())->get('/test-probe/recent')->assertOk();
});
