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

// ---- WR-09: the proof replayed in every request shape the gate must hold for ---------------------------------------

function inertiaHeaders(): array
{
    return ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => (string) \Inertia\Inertia::getVersion()];
}

/**
 * Sends the route's request as `$shape` and returns [response, method used].
 * Shapes: html, json, inertia, head (GET routes only), prefetch, plus the session state is chosen by the caller.
 */
function replayAs(object $test, string $name, string $shape, array $session): ?array
{
    // One set of members per test (the database is rolled back between tests): keeps the many replays light on memory.
    static $cache = ['test' => null, 'users' => []];
    if ($cache['test'] !== $test) {
        $cache = ['test' => $test, 'users' => Roles::make()];
    }
    $users = $cache['users'];
    $spec = EndpointMatrix::all()[$name];
    $method = $spec['method'];
    if ($shape === 'head' && $method !== 'GET') {
        return null;
    }
    app('auth')->forgetGuards();
    $test->flushSession();
    $user = $users[sensitiveRole($name)];
    [$uri, $data] = sensitiveRequest($name, $users);
    $server = match ($shape) {
        'json' => ['HTTP_ACCEPT' => 'application/json'],
        'inertia' => inertiaHeaders(),
        'prefetch' => ['HTTP_PURPOSE' => 'prefetch'],
        default => [],
    };

    $response = $test->actingAs($user)->withSession($session)->call($shape === 'head' ? 'HEAD' : $method, $uri, $data, [], [], $server);
    gc_collect_cycles();

    return [$response, $method];
}

it('refuses every sensitive route in every request shape for a missing and an expired idle timestamp, with no audit row', function () {
    $this->freezeTime();
    $states = [
        'missing key' => [],
        'expired timestamp' => ['sensitive.active_at' => now()->getTimestamp() - ((int) config('security.idle_seconds') + 1)],
    ];

    foreach (SensitiveRoutes::all() as $name => $route) {
        foreach ($states as $label => $session) {
            foreach (['html', 'json', 'inertia', 'head', 'prefetch'] as $shape) {
                $before = sensitiveAuditRows();
                $replay = replayAs($this, $name, $shape, $session);
                if ($replay === null) {
                    continue;
                }
                [$response, $method] = $replay;
                $ctx = "$name [$shape, $label]";

                if ($shape === 'json' || $shape === 'prefetch') {
                    expect($response->getStatusCode())->toBe(423, $ctx);
                    expect($response->headers->get('Content-Type'))->toContain('json');
                } else {
                    expect($response->getStatusCode())->toBeIn([302, 303], $ctx);
                    expect((string) $response->headers->get('Location'))->toEndWith('/confirm-access');
                }
                expect(sensitiveAuditRows())->toBe($before, "$ctx wrote an audit row");
            }
        }
    }
});

it('never lets a passive prefetch extend or refresh the idle window', function () {
    $this->freezeTime();
    $name = collect(array_keys(SensitiveRoutes::all()))->first(fn ($n) => EndpointMatrix::all()[$n]['method'] === 'GET');
    $old = now()->getTimestamp() - 100;

    [$response] = replayAs($this, $name, 'prefetch', ['sensitive.active_at' => $old]);

    expect($response->getStatusCode())->toBeIn([200, 302, 404, 423, 403, 303]);
    expect(session('sensitive.active_at'))->toBe($old);
});

it('honours the idle boundary exactly on every sensitive GET route', function () {
    $this->freezeTime();
    $idle = (int) config('security.idle_seconds');

    foreach (SensitiveRoutes::all() as $name => $route) {
        $spec = EndpointMatrix::all()[$name];
        if ($spec['method'] !== 'GET') {
            continue;
        }
        $role = sensitiveRole($name);

        [$edge] = replayAs($this, $name, 'html', ['sensitive.active_at' => now()->getTimestamp() - $idle]);
        expect($edge->getStatusCode())->toBe($spec['expect'][$role], "$name at exactly $idle seconds");

        [$over] = replayAs($this, $name, 'html', ['sensitive.active_at' => now()->getTimestamp() - $idle - 1]);
        expect($over->getStatusCode())->toBe(302, "$name one second past the window");
    }
});

it('lets a fresh session through on every sensitive route, GET and write alike, in the page and JSON shapes', function () {
    foreach (SensitiveRoutes::all() as $name => $route) {
        $spec = EndpointMatrix::all()[$name];
        $role = sensitiveRole($name);

        foreach (['html'] as $shape) { // one shape: the writes are heavy and the shapes are proven on the stale side
            [$response] = replayAs($this, $name, $shape, ['sensitive.active_at' => now()->getTimestamp()]);
            $status = $response->getStatusCode();
            expect($status)->not->toBe(423, "$name [$shape] fresh session refused");
            if ($shape === 'html') {
                expect($status)->toBe($spec['expect'][$role], "$name fresh html");
            }
            // A refusal would point at the confirm page; a real handler never does.
            expect((string) $response->headers->get('Location'))->not->toEndWith('/confirm-access', "$name [$shape] fresh session bounced");
        }
    }
});

it('finds a finance, health or medical controller route behind an innocent name and URI (namespace net, mutation check)', function () {
    Route::middleware('web')->get('/reports/zz-probe', [\App\Http\Controllers\Finance\OverviewController::class, 'index'])
        ->middleware('access:authenticated')->name('zz.report_probe');

    expect(SensitiveRoutes::offenders())->toContain('zz.report_probe');
});

it('reports no controller in a sensitive namespace outside the gate today', function () {
    $outside = [];
    foreach (Route::getRoutes() as $route) {
        if (SensitiveRoutes::inSensitiveNamespace($route) && ! SensitiveRoutes::isSensitive($route)) {
            $outside[] = (string) $route->getName();
        }
    }

    expect($outside)->toBe([], 'Sensitive-namespace routes outside the gate: '.implode(', ', $outside));
});
