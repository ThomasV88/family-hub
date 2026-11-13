<?php

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\ModulePermission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/test-probe/health', fn () => 'ok')->middleware('access:module,health,view')->name('test.probe.health');
        Route::post('/test-probe/todo', fn () => 'ok')->middleware('access:module,todo,edit')->name('test.probe.todo');
        Route::get('/test-probe/undeclared', fn () => 'ok')->name('test.probe.undeclared');
        Route::get('/test-probe/admin', fn () => 'ok')->middleware('access:admin')->name('test.probe.admin');
        Route::get('/test-probe/guest', fn () => 'ok')->middleware('access:guest')->name('test.probe.guest');
    });
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('treats missing rows as none and a null user as denied', function () {
    $user = User::factory()->create();
    $gate = app(AccessGate::class);

    foreach (Module::cases() as $module) {
        expect($gate->level($user, $module))->toBe(AccessLevel::None)
            ->and($gate->allows($user, $module, AccessLevel::View))->toBeFalse()
            ->and($gate->allows(null, $module, AccessLevel::View))->toBeFalse();
    }
});

it('gives an admin without grants no finance or health access', function () {
    $admin = User::factory()->admin()->create();
    $gate = app(AccessGate::class);

    expect($gate->isAdmin($admin))->toBeTrue()
        ->and($gate->allows($admin, Module::Finance, AccessLevel::View))->toBeFalse()
        ->and($gate->allows($admin, Module::Health, AccessLevel::View))->toBeFalse();
});

it('requireAccess aborts 403 when denied', function () {
    $user = User::factory()->withGrant(Module::Todo, AccessLevel::View)->create();
    $gate = app(AccessGate::class);

    $gate->requireAccess($user, Module::Todo, AccessLevel::View);
    expect(fn () => $gate->requireAccess($user, Module::Todo, AccessLevel::Edit))
        ->toThrow(HttpException::class);
});

it('drops the per-request cache on forget', function () {
    $user = User::factory()->withGrant(Module::Todo, AccessLevel::Edit)->create();
    $gate = app(AccessGate::class);
    expect($gate->level($user, Module::Todo))->toBe(AccessLevel::Edit);

    ModulePermission::where('user_id', $user->id)->update(['level' => 0]);
    expect($gate->level($user, Module::Todo))->toBe(AccessLevel::Edit);
    $gate->forget($user);
    expect($gate->level($user, Module::Todo))->toBe(AccessLevel::None);
});

it('does not share the cache across requests (scoped binding)', function () {
    $user = User::factory()->withGrant(Module::Todo, AccessLevel::Edit)->create();
    $first = app(AccessGate::class);
    $first->level($user, Module::Todo);

    app()->forgetScopedInstances();

    expect(app(AccessGate::class))->not->toBe($first);
});

it('rejects duplicate rows, level 3 and unknown modules in the database', function () {
    $user = User::factory()->create();
    $insert = fn (string $module, int $level) => ModulePermission::query()->insert([
        'user_id' => $user->id, 'module' => $module, 'level' => $level, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $insert('todo', 1);
    expect(fn () => $insert('todo', 2))->toThrow(QueryException::class);
    expect(fn () => $insert('wishlist', 3))->toThrow(QueryException::class);
    expect(fn () => $insert('settings', 1))->toThrow(QueryException::class);
});

it('enforces module and level on probe routes', function () {
    $noGrants = User::factory()->create();
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $healthViewer = User::factory()->withGrant(Module::Health, AccessLevel::View)->create();

    $this->actingAs($noGrants)->get('/test-probe/health')->assertForbidden();
    $this->actingAs($parent)->get('/test-probe/health')->assertForbidden();
    $this->actingAs($healthViewer)->get('/test-probe/health')->assertOk();

    $guest = User::factory()->withPreset(Preset::Guest)->create();
    $child = User::factory()->withPreset(Preset::Child)->create();
    $this->actingAs($guest)->post('/test-probe/todo')->assertForbidden();
    $this->actingAs($child)->post('/test-probe/todo')->assertOk();
});

it('fails closed on a route without an access rule and keeps 404 for unknown urls', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/test-probe/undeclared')->assertForbidden();
    $this->get('/no-such-url')->assertNotFound();
});

it('limits access:admin to admins and sends guests to login', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/test-probe/admin')->assertOk();
    $this->actingAs(User::factory()->withPreset(Preset::Parent)->create())->get('/test-probe/admin')->assertForbidden();
    auth()->logout();
    $this->get('/test-probe/admin')->assertRedirect('/login');
});

it('redirects signed-in users away from access:guest routes', function () {
    $this->actingAs(User::factory()->create())->get('/test-probe/guest')->assertRedirect('/');
});

it('shares auth.access as levels for members and an empty object for guests', function () {
    $this->actingAs(User::factory()->withPreset(Preset::Child)->create())->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('auth.access', [
            'todo' => 'edit', 'wishlist' => 'edit', 'finance' => 'none', 'health' => 'none',
        ]));

    auth()->logout();
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('auth.access', []));
});
