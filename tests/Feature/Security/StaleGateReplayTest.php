<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\AuditEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Invitation;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

// Server half of the "Confirm it's you" dialog (issue 2). The client holds the payload in memory; here we prove the
// contract it relies on: a stale write is refused in place (423 with the methods, nothing saved, no redirect that
// would lose the form), the dialog's POST /confirm-access opens the windows, and replaying the same write saves it once.

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
    Notification::fake();
});

function sgrStale(): array
{
    $old = now()->subHours(3)->getTimestamp();

    return ['auth.password_confirmed_at' => $old, 'sensitive.active_at' => $old];
}

function sgrMember(): User
{
    $user = User::factory()->admin()->withGrant(Module::Finance, AccessLevel::Edit)->create()->refresh();
    MedicalFixtures::grantHealth($user);

    return $user;
}

function sgrInertia(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion()];
}

/** @return array<string, array{0: string, 1: string, 2: callable, 3: callable}> */
function sgrWrites(): array
{
    return [
        'finance account (the reported case)' => ['post', '/settings/finance-accounts', fn () => ['name' => 'Joint', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => '', 'balance_as_of' => '2026-01-01', 'member_ids' => []], fn () => FinanceAccount::count()],
        'invitation' => ['post', '/settings/invitations', fn () => ['email' => 'eva@example.test', 'preset' => 'child', 'locale' => 'sk'], fn () => Invitation::count()],
        'finance transaction' => ['post', '/finance/transactions', function () {
            $account = FinanceAccount::factory()->withMembers(User::query()->orderByDesc('id')->firstOrFail())->create(['name' => 'Main']);

            return ['kind' => 'expense', 'amount' => '12,50', 'booked_on' => '2026-01-06', 'description' => 'Bread', 'account_id' => $account->id];
        }, fn () => FinanceTransaction::count()],
        'finance category' => ['post', '/finance/categories', fn () => ['name' => 'Pets', 'kind' => 'expense'], fn () => FinanceCategory::query()->where('name', 'Pets')->count()],
        'health person' => ['post', '/health/people', fn () => ['name' => 'Ada Novak', 'birth_date' => '2015-03-04', 'sex' => 'female', 'blood_type' => 'AB-', 'notes' => ''], fn () => MedicalSubject::count()],
    ];
}

it('refuses a stale Inertia write in place, then saves it exactly once after confirming', function (string $verb, string $uri, callable $payload, callable $count) {
    $user = sgrMember();
    $this->actingAs($user)->withSession(sgrStale());
    $data = $payload();
    $before = $count();
    $audit = AuditEntry::count();

    $refused = $this->withHeaders(sgrInertia())->{$verb}($uri, $data);
    $this->flushHeaders();
    $refused->assertStatus(423)->assertJsonPath('error', fn ($e) => in_array($e, ['confirm_required', 'reauth_required'], true))
        ->assertJsonPath('methods.password', true)->assertJsonPath('methods.totp', false)->assertJsonPath('methods.passkey', false);
    expect($refused->headers->get('Location'))->toBeNull()
        ->and($refused->headers->get('Cache-Control'))->toContain('no-store')
        ->and($count())->toBe($before)
        ->and(AuditEntry::count())->toBe($audit);

    $this->postJson('/confirm-access', ['password' => 'password'])->assertOk()->assertJson(['confirmed' => true]);

    $this->withHeaders(sgrInertia())->{$verb}($uri, $data)->assertRedirect();
    expect($count())->toBe($before + 1);
})->with(array_map(fn ($case) => $case, sgrWrites()));

it('does not save when the replay is refused again, and never saves without the confirmation', function () {
    $user = sgrMember();
    $this->actingAs($user)->withSession(sgrStale());
    $data = ['name' => 'Joint', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => '', 'balance_as_of' => '2026-01-01', 'member_ids' => []];

    $this->withHeaders(sgrInertia())->post('/settings/finance-accounts', $data)->assertStatus(423);
    $this->withHeaders(sgrInertia())->post('/settings/finance-accounts', $data)->assertStatus(423);
    $this->flushHeaders();
    $this->postJson('/confirm-access', ['password' => 'wrong'])->assertStatus(422);
    $this->withHeaders(sgrInertia())->post('/settings/finance-accounts', $data)->assertStatus(423);

    expect(FinanceAccount::count())->toBe(0);
});

it('refuses a stale JSON write with the methods and saves once after confirming', function () {
    $admin = User::factory()->admin()->create()->refresh();
    $this->actingAs($admin)->withSession(sgrStale());
    $body = ['idle_seconds' => 7200, 'confirm_window_seconds' => 900];

    $this->putJson('/settings/security/timings', $body)->assertStatus(423)
        ->assertJson(['error' => 'confirm_required', 'methods' => ['password' => true]]);
    expect(DB::table('app_settings')->where('key', 'like', 'security.%')->count())->toBe(0);

    $this->postJson('/confirm-access', ['password' => 'password'])->assertOk();
    $this->putJson('/settings/security/timings', $body)->assertOk();
    expect(AuditEntry::where('event', 'security_policy_changed')->count())->toBe(2);
});

it('keeps redirecting stale GET navigations to the confirm page (the page flow stays)', function () {
    $user = sgrMember();
    $this->actingAs($user)->withSession(sgrStale());

    $this->get('/finance')->assertRedirect('/confirm-access');
});

it('never offers the password to a member with a second factor in the 423 body', function () {
    $user = sgrMember();
    \Tests\Support\Totp::enable($user);
    $this->actingAs($user)->withSession(sgrStale());

    $this->withHeaders(sgrInertia())->post('/settings/finance-accounts', ['name' => 'X', 'type' => 'bank', 'currency' => 'EUR', 'balance_as_of' => '2026-01-01'])
        ->assertStatus(423)->assertJsonPath('methods.password', false)->assertJsonPath('methods.totp', true);
    $this->flushHeaders();
    $this->postJson('/confirm-access', ['password' => 'password'])->assertStatus(422);
    expect(FinanceAccount::count())->toBe(0);
});

it('still sends a member who must enrol to the security page instead of the dialog', function () {
    $user = sgrMember();
    DB::table('app_settings')->upsert([['key' => 'require_second_factor', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()]], ['key']);
    $this->actingAs($user)->withSession(['sensitive.active_at' => now()->getTimestamp()]);

    $this->withHeaders(sgrInertia())->post('/finance/categories', ['name' => 'Pets', 'kind' => 'expense'])->assertRedirect('/settings/security');
    expect(FinanceCategory::query()->where('name', 'Pets')->count())->toBe(0);
});
