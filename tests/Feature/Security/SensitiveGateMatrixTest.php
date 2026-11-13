<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\AuditEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceRecurring;
use App\Models\ModulePermission;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EndpointMatrix;
use Tests\Support\Roles;
use Tests\Support\SensitiveRoutes;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->useRealSensitiveSession();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function sensitiveAuditRows(): int
{
    return AuditEntry::whereIn('event', ['finance_read', 'finance_write', 'health_read', 'health_write'])->count();
}

/** The first signed-in role whose matrix row expects success. */
function sensitiveRole(string $name): ?string
{
    foreach (EndpointMatrix::all()[$name]['expect'] as $role => $status) {
        if ($role !== 'guest' && $status < 400) {
            return $role;
        }
    }

    return null;
}

/** @return array{0: string, 1: array<string, mixed>} uri and data for the matrix row, built for these users */
function sensitiveRequest(string $name, array $users): array
{
    $spec = EndpointMatrix::all()[$name];
    $params = isset($spec['params']) ? $spec['params']($users) : [];
    $uri = $spec['uri'];
    foreach ($params as $key => $value) {
        $uri = str_replace('{'.$key.'}', (string) $value, $uri);
    }
    $data = $spec['data'] ?? [];
    if ($data instanceof Closure) {
        $data = $data($users, $params);
    }

    return [$uri, $data];
}

it('finds the finance routes by their middleware', function () {
    expect(count(SensitiveRoutes::all()))->toBeGreaterThanOrEqual(25);
});

it('has a matrix row with a success role for every sensitive route', function () {
    $bad = [];
    foreach (array_keys(SensitiveRoutes::all()) as $name) {
        if (! array_key_exists($name, EndpointMatrix::all()) || sensitiveRole($name) === null) {
            $bad[] = $name;
        }
    }

    expect($bad)->toBe([], 'Sensitive routes without a usable matrix row: '.implode(', ', $bad));
});

it('refuses a stale real session on every sensitive route and writes no audit row', function () {
    foreach (SensitiveRoutes::all() as $name => $route) {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $spec = EndpointMatrix::all()[$name];
        $users = Roles::make();
        $user = $users[sensitiveRole($name)];
        [$uri, $data] = sensitiveRequest($name, $users);
        $method = $spec['method'];
        $before = sensitiveAuditRows();

        $response = $this->actingAs($user)->call($method, $uri, $data);
        expect($response->getStatusCode())->toBe($method === 'GET' ? 302 : 303, "$name html");
        expect((string) $response->headers->get('Location'))->toEndWith('/confirm-access');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        [$uri, $data] = sensitiveRequest($name, $users);
        $json = $this->actingAs($user)->call($method, $uri, $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
        expect($json->getStatusCode())->toBe(423, "$name json");
        expect($json->json('error'))->toBe('reauth_required');

        expect(sensitiveAuditRows())->toBe($before, "$name wrote an audit row while stale");
    }
});

it('lets a fresh session through on every sensitive GET route', function () {
    foreach (SensitiveRoutes::all() as $name => $route) {
        $spec = EndpointMatrix::all()[$name];
        if ($spec['method'] !== 'GET') {
            continue;
        }
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $users = Roles::make();
        $role = sensitiveRole($name);
        [$uri, $data] = sensitiveRequest($name, $users);

        $response = $this->actingAs($users[$role])->withSession(['sensitive.active_at' => now()->getTimestamp()])->call('GET', $uri, $data);

        expect($response->getStatusCode())->toBe($spec['expect'][$role], "$name fresh");
    }
});

it('has no finance, health or medical route outside the gate unless allowlisted', function () {
    $offenders = SensitiveRoutes::offenders();

    expect($offenders)->toBe([], 'Ungated sensitive-looking routes: '.implode(', ', $offenders));
});

it('allowlists each entry with a reason', function () {
    foreach (SensitiveRoutes::allowlist() as $prefix => $reason) {
        expect($reason)->not->toBe('');
    }
});

it('reports a throwaway finance route that forgot its access rule (mutation check)', function () {
    Route::middleware('web')->get('/finance/zz-probe', fn () => 'ok')->middleware('access:authenticated')->name('finance.zz_probe');

    expect(SensitiveRoutes::offenders())->toContain('finance.zz_probe');
});

it('gates a throwaway health route declared the right way', function () {
    Route::middleware('web')->get('/health/zz-probe', fn () => 'ok')->middleware('access:module,health,view')->name('health.zz_probe');
    $user = User::factory()->create();
    ModulePermission::updateOrCreate(['user_id' => $user->id, 'module' => Module::Health->value], ['level' => AccessLevel::View->value]);
    app()->forgetScopedInstances();

    expect(array_keys(SensitiveRoutes::all()))->toContain('health.zz_probe')
        ->and(SensitiveRoutes::offenders())->not->toContain('health.zz_probe');

    $this->actingAs($user)->get('/health/zz-probe')->assertStatus(302)->assertRedirect('/confirm-access');
    expect(sensitiveAuditRows())->toBe(0);
});

// PROVISIONAL (orchestrator), owner confirms in 06-13 Task 2: the Home card stays visible on an idle session.
it('keeps the Home still-to-pay card visible for a stale finance member (allowlisted decision)', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $account = FinanceAccount::factory()->withMembers($user)->create();
    FinanceRecurring::factory()->onAccount($account)->startingOn(now(config('family.timezone'))->startOfMonth()->toDateString())
        ->create(['name' => 'Bill', 'expected_amount_minor' => 1000]);

    $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Home')->has('financeCard.paymentsCount'));
});


// CR-01: routes that grant or widen access to sensitive data need the recent-confirmation window.
function grantSnapshot(): array
{
    return json_decode(json_encode([
        'audit' => AuditEntry::count(),
        'permissions' => ModulePermission::query()->orderBy('id')->get()->toArray(),
        'invitations' => \Illuminate\Support\Facades\DB::table('invitations')->orderBy('id')->get()->toArray(),
        'accounts' => FinanceAccount::query()->orderBy('id')->get()->toArray(),
        'members' => \Illuminate\Support\Facades\DB::table('finance_account_members')->get()->toArray(),
    ]), true);
}

it('lists only routes that exist and are gated by access:recent for access-granting admin routes', function () {
    foreach (SensitiveRoutes::accessGranting() as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("$name is not a route");
        expect($route->gatherMiddleware())->toContain('access:admin')->toContain('access:recent');
    }
});

it('refuses a stale admin session on every access-granting route and changes nothing', function () {
    foreach (SensitiveRoutes::accessGranting() as $name) {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $spec = EndpointMatrix::all()[$name];
        $users = Roles::make();
        $admin = $users['admin'];
        [$uri, $data] = sensitiveRequest($name, $users);
        $before = grantSnapshot();

        $html = $this->actingAs($admin)->call($spec['method'], $uri, $data);
        expect($html->getStatusCode())->toBeIn([302, 303, 423], "$name html");
        expect((string) $html->headers->get('Location'))->toEndWith('/confirm-access');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $json = $this->actingAs($admin)->call($spec['method'], $uri, $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
        expect($json->getStatusCode())->toBe(423, "$name json");

        expect(grantSnapshot())->toBe($before, "$name changed state while stale");
    }
});

it('keeps a member without admin at 403 on access-granting routes even when stale', function () {
    foreach (SensitiveRoutes::accessGranting() as $name) {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $spec = EndpointMatrix::all()[$name];
        $users = Roles::make();
        [$uri, $data] = sensitiveRequest($name, $users);
        $member = $users['parent'];

        $this->actingAs($member)->call($spec['method'], $uri, $data, [], [], ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();
    }
});

it('has no admin grant route under the grant prefixes that is neither listed nor allowlisted', function () {
    $bad = SensitiveRoutes::unclassifiedGrantRoutes();

    expect($bad)->toBe([], 'Unclassified grant routes: '.implode(', ', $bad));
});

it('reports a throwaway grant route that was never classified (mutation check)', function () {
    Route::middleware('web')->post('/settings/invitations/zz-probe', fn () => 'ok')->middleware('access:admin')->name('settings.invitations.zz_probe');

    expect(SensitiveRoutes::unclassifiedGrantRoutes())->toContain('settings.invitations.zz_probe');
});
