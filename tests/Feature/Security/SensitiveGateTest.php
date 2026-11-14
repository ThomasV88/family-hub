<?php

use App\Access\AccessLevel;
use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Security\SecondFactorPolicy;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function gateParent(): User
{
    return User::factory()->withPreset(Preset::Parent)->create();
}

function gateFinanceRows(): int
{
    return AuditEntry::whereIn('event', ['finance_read', 'finance_write'])->count();
}

it('sends a stale finance GET to the confirm page without an audit row', function () {
    $user = gateParent();

    $this->actingAs($user)->get('/finance')->assertStatus(302)->assertRedirect('/confirm-access')
        ->assertSessionHas('url.intended', '/finance');
    expect(gateFinanceRows())->toBe(0);
});

it('lets a fresh session through, slides the window and audits the read', function () {
    $user = gateParent();
    $old = now()->subSeconds(60)->getTimestamp();

    $this->actingAs($user)->withSession(['sensitive.active_at' => $old])->get('/finance')->assertOk();

    expect(session('sensitive.active_at'))->toBeGreaterThan($old)
        ->and(AuditEntry::where('event', 'finance_read')->count())->toBe(1);
});

it('refuses prefetch and passive requests when stale and never slides when fresh', function (string $header, string $value) {
    $user = gateParent();

    $this->actingAs($user)->withHeaders([$header => $value])->get('/finance')
        ->assertStatus(423)->assertExactJson(['error' => 'reauth_required'])
        ->assertSessionMissing('url.intended');

    $old = now()->subSeconds(60)->getTimestamp();
    $this->actingAs($user)->withSession(['sensitive.active_at' => $old])->withHeaders([$header => $value])->get('/finance')->assertOk();
    expect(session('sensitive.active_at'))->toBe($old);
})->with([['Purpose', 'prefetch'], ['X-Fh-Passive', '1']]);

it('counts a write with a passive header as activity (reads stay passive)', function () {
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject([], [[$user, AccessLevel::Edit]]);
    $old = now()->subSeconds(60)->getTimestamp();

    $this->actingAs($user)->withSession(['sensitive.active_at' => $old])->withHeaders(['X-Fh-Passive' => '1'])
        ->patch("/health/people/{$person->id}", ['name' => 'Renamed'])->assertRedirect();

    expect(session('sensitive.active_at'))->toBeGreaterThan($old);
});

it('answers a stale JSON request with 423 and remembers the same-origin referer', function () {
    $user = gateParent();

    $this->actingAs($user)->withHeader('Referer', url('/finance/import'))
        ->postJson('/finance/import/preview')->assertStatus(423)->assertExactJson(['error' => 'reauth_required'])
        ->assertSessionHas('url.intended', '/finance/import')->assertSessionHas('status', 'reauth_interrupted');

    $this->flushSession();
    $this->actingAs($user)->withHeader('Referer', 'https://evil.example/x')
        ->postJson('/finance/import/preview')->assertStatus(423)->assertSessionHas('url.intended', '/');
});

it('answers a stale non-navigational load with 423', function () {
    $this->actingAs(gateParent())->withHeader('Sec-Fetch-Mode', 'no-cors')->get('/finance')->assertStatus(423);
});

it('bounces a stale Inertia write with 303 and leaves the record alone', function () {
    $user = gateParent();
    $account = FinanceAccount::factory()->withMembers($user)->create();
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(1000)->create(['booked_on' => '2026-03-05', 'description' => 'Keep']);

    $this->actingAs($user)->withHeaders(['X-Inertia' => 'true', 'Referer' => url('/finance/transactions')])
        ->patch('/finance/transactions/'.$tx->id, ['amount' => '1,00', 'description' => 'Changed'])
        ->assertStatus(303)->assertRedirect('/confirm-access')
        ->assertSessionHas('status', 'reauth_interrupted')->assertSessionHas('url.intended', '/finance/transactions');

    expect($tx->fresh()->description)->toBe('Keep')->and(gateFinanceRows())->toBe(0);
});

it('keeps 403 for members without finance access', function () {
    $child = User::factory()->withPreset(Preset::Child)->create();

    $this->actingAs($child)->get('/finance')->assertForbidden();
    expect(AuditEntry::where('event', 'access_denied')->count())->toBe(1);
});

it('never interrupts other modules and never slides for them', function () {
    $user = gateParent();

    foreach (['/', '/todo', '/settings'] as $uri) {
        $this->actingAs($user)->get($uri)->assertOk();
        expect(session('sensitive.active_at'))->toBeNull();
    }
});

it('sends a member under the requirement to Security first, even when stale', function () {
    $user = gateParent();
    app(SecondFactorPolicy::class)->setRequired(true);

    $this->actingAs($user)->get('/finance')->assertStatus(302)->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'second_factor_required')->assertSessionHas('url.intended', '/finance');
    $this->actingAs($user)->getJson('/finance')->assertStatus(423)->assertExactJson(['error' => 'second_factor_required']);

    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => 'c-'.$user->id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($user)->get('/finance')->assertRedirect('/confirm-access');
});
