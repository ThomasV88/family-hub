<?php

use App\Models\Wish;
use App\Models\WishReservation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Tests\Support\WishlistPrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('serves recipient-side viewers byte-identical surfaces with and without reservations and issues no reservation SQL', function (string $side) {
    $ctx = WishlistPrivacy::scenario();
    $viewer = $ctx[$side];

    expect(WishlistPrivacy::surfaces())->not->toBeEmpty();

    $before = WishlistPrivacy::capture($this, $viewer, $ctx);
    $auditBefore = DB::table('audit_logs')->count();

    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);
    expect(DB::table('audit_logs')->count())->toBe($auditBefore);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $after = WishlistPrivacy::capture($this, $viewer, $ctx);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q));
    DB::disableQueryLog();

    expect($after)->toEqual($before);
    // Guard against a vacuous pass: the Inertia captures really are rendered pages.
    expect(collect($after)->filter(fn ($b, $k) => str_starts_with($k, 'inertia ') && str_starts_with($b, "200\n\n{")))->not->toBeEmpty();

    foreach ($after as $key => $body) {
        $scan = WishlistPrivacy::scannable($body);
        expect($scan)->not->toContain(WishlistPrivacy::CANARY_NAME, "canary name in $key")
            ->and($scan)->not->toContain('7777', "canary amount in $key");
    }

    $mentioning = $queries->filter(fn ($q) => str_contains($q, 'wish_reservations') || str_contains($q, 'wish_contributions'));
    expect($mentioning->all())->toBe([]);
})->with(['owner', 'manager']);

it('answers every giving write identically with and without a reservation for the owner and the manager', function (string $side) {
    $writes = WishlistPrivacy::givingWrites();
    $ctx = WishlistPrivacy::scenario();
    $viewer = $ctx[$side];

    $run = function () use ($writes, $ctx, $viewer): array {
        $out = [];
        foreach ($writes as $route) {
            $url = route($route->getName(), WishlistPrivacy::params($route, $ctx), false);
            $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
            $this->app['auth']->forgetGuards();
            $this->app['cache']->store()->flush();
            $this->flushSession();
            $response = $this->actingAs($viewer)->call($method, $url, ['amount' => '12,50']);
            $response->assertForbidden();
            $out[$route->getName()] = WishlistPrivacy::normalise($response);
        }

        return $out;
    };

    $without = $run();
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);
    $with = $run();

    expect($with)->toEqual($without);
    // Nothing changed behind the 403.
    expect(WishReservation::query()->count())->toBe(1);
})->with(['owner', 'manager']);

it('never serialises reservation data through any relation of Wish', function () {
    $ctx = WishlistPrivacy::scenario();
    $auditBefore = DB::table('audit_logs')->count();
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);

    $relations = [];
    foreach ((new ReflectionClass(Wish::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $type = $method->getReturnType();
        if ($method->class === Wish::class && $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Relation::class)) {
            $relations[] = $method->getName();
        }
    }
    expect($relations)->not->toBeEmpty();

    $json = Wish::query()->with($relations)->get()->toJson();
    expect($json)->not->toContain('reservation')
        ->and($json)->not->toContain('contribution')
        ->and($json)->not->toContain(WishlistPrivacy::CANARY_NAME)
        ->and(WishlistPrivacy::scannable($json))->not->toContain('7777')
        ->and(DB::table('audit_logs')->count())->toBe($auditBefore);
});

it('gives a viewer identical own-list props whether or not they hold reservations on other lists', function () {
    $ctx = WishlistPrivacy::scenario();
    // The giver is also an owner with a list of their own and reserves on the owner's list.
    Wish::factory()->create(['owner_id' => $ctx['giver']->id, 'title' => 'Giver own wish']);

    $props = function () use ($ctx) {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $response = $this->actingAs($ctx['giver'])->get('/wishlist', ['X-Inertia' => 'true', 'X-Inertia-Version' => WishlistPrivacy::inertiaVersion()]);

        return $response->json('props');
    };

    $before = $props();
    WishReservation::factory()->create(['wish_id' => $ctx['wish']->id, 'user_id' => $ctx['giver']->id]);
    $after = $props();

    unset($before['flash'], $after['flash']);
    expect($after)->toEqual($before)
        ->and($after['list']['viewer_is_recipient'])->toBeTrue()
        ->and($after['list']['wishes'][0])->not->toHaveKey('reservation');
});
