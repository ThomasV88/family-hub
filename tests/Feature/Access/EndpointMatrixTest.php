<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Http\Middleware\EnsureRouteDeclaresAccess;
use App\Models\ModulePermission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Tests\Support\EndpointMatrix;
use Tests\Support\Roles;

/** @return list<Illuminate\Routing\Route> application routes (ignored ones removed) */
function applicationRoutes(): array
{
    return array_values(array_filter(
        iterator_to_array(Route::getRoutes()),
        fn ($route) => ! EndpointMatrix::isIgnored($route->uri()),
    ));
}

it('has a matrix row for every named route and no unnamed routes', function () {
    $missing = [];
    foreach (applicationRoutes() as $route) {
        $name = $route->getName();
        if ($name === null) {
            $missing[] = 'UNNAMED:'.$route->uri();
        } elseif (! array_key_exists($name, EndpointMatrix::all())) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([], 'Routes without matrix row: '.implode(', ', $missing));
});

it('has no matrix row for a route that does not exist', function () {
    $names = array_map(fn ($route) => $route->getName(), applicationRoutes());

    expect(array_diff(array_keys(EndpointMatrix::all()), $names))->toBe([]);
});

it('declares an access rule on every route', function () {
    $undeclared = [];
    foreach (applicationRoutes() as $route) {
        $declared = collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'access:'));
        if (! $declared && ! array_key_exists($route->getName(), EnsureRouteDeclaresAccess::FORTIFY_RULES)) {
            $undeclared[] = $route->getName() ?? 'UNNAMED:'.$route->uri();
        }
    }

    expect($undeclared)->toBe([], 'Routes without access rule: '.implode(', ', $undeclared));
});

it('lists all seven roles in every matrix row', function () {
    foreach (EndpointMatrix::all() as $name => $spec) {
        expect(array_keys($spec['expect']))->toEqualCanonicalizing(Roles::all(), "row $name");
    }
});

it('enforces the expected status per role', function (string $name) {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $spec = EndpointMatrix::all()[$name];
    $users = Roles::make();

    foreach (Roles::all() as $role) {
        $this->app['auth']->forgetGuards();
        $this->app['cache']->store()->flush();
        $this->flushSession();

        $params = isset($spec['params']) ? $spec['params']($users) : [];
        $uri = $spec['uri'];
        foreach ($params as $key => $value) {
            $uri = str_replace('{'.$key.'}', (string) $value, $uri);
        }

        $request = $users[$role] ? $this->actingAs($users[$role]) : $this;
        $data = $spec['data'] ?? [];
        if ($data instanceof Closure) {
            $data = $data($users, $params);
        }
        $response = $request->call($spec['method'], $uri, $data);

        expect($response->getStatusCode())->toBe($spec['expect'][$role], "$name as $role");
    }
})->with(fn () => array_keys(EndpointMatrix::all()));

it('reads real grants: revoking a grant flips a todo probe from 200 to 403', function () {
    Route::middleware('web')->get('/test-probe/todo-view', fn () => 'ok')
        ->middleware('access:module,todo,edit')->name('test.probe.todo-view');

    $child = Roles::make()['child'];

    $this->actingAs($child)->get('/test-probe/todo-view')->assertOk();

    ModulePermission::where('user_id', $child->id)->where('module', Module::Todo->value)
        ->update(['level' => AccessLevel::None->value]);
    app()->forgetScopedInstances();

    $this->actingAs($child)->get('/test-probe/todo-view')->assertForbidden();
});
