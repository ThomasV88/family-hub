<?php

use App\Models\WishReservation;
use App\Wishlist\Groups\GroupMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Tests\Support\WishlistPrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/** @return array<string, string> normalised responses of every giving write as $viewer */
function grpPrivRunWrites($test, array $ctx, $viewer, ?int $expect = 403): array
{
    $out = [];
    foreach (WishlistPrivacy::givingWrites() as $route) {
        $url = route($route->getName(), WishlistPrivacy::params($route, $ctx), false);
        $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
        app('auth')->forgetGuards();
        app('cache')->store()->flush();
        $test->flushSession();
        $response = $test->actingAs($viewer)->call($method, $url, ['amount' => '12,50']);
        if ($expect !== null) {
            $response->assertStatus($expect);
        }
        $out[$route->getName()] = WishlistPrivacy::normalise($response);
    }

    return $out;
}

it('serves gifts-group members byte-identical surfaces with and without reservations and issues no reservation SQL', function (string $side) {
    $ctx = WishlistPrivacy::groupScenario(GroupMode::Gifts);
    $viewer = $ctx[$side];

    expect(WishlistPrivacy::surfaces())->not->toBeEmpty();

    $before = WishlistPrivacy::capture($this, $viewer, $ctx);
    $auditBefore = DB::table('audit_logs')->count();

    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);
    expect(DB::table('audit_logs')->count())->toBe($auditBefore);

    $after = WishlistPrivacy::capture($this, $viewer, $ctx);

    DB::flushQueryLog();
    DB::enableQueryLog();
    WishlistPrivacy::capture($this, $viewer, $ctx, includeGiving: false);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q));
    DB::disableQueryLog();

    expect($after)->toEqual($before);
    // The group variants are really covered, and at least one is a rendered 200 page.
    expect(collect($after)->keys()->filter(fn ($k) => str_contains($k, '?group='.$ctx['group']->id)))->not->toBeEmpty();
    expect(collect($after)->filter(fn ($b, $k) => str_starts_with($k, 'inertia ') && str_contains($k, '?group=') && str_starts_with($b, "200\n\n{")))->not->toBeEmpty();

    foreach ($after as $key => $body) {
        $scan = WishlistPrivacy::scannable($body);
        expect($scan)->not->toContain(WishlistPrivacy::CANARY_NAME, "canary name in $key")
            ->and($scan)->not->toContain('7777', "canary amount in $key");
    }

    // memberB legitimately views memberA's personal list on the giver side (the generic ?member= URLs), and the
    // giver branch issues an empty-set lookup (`where 0 = 1`, no row can be read) when that list has no wishes.
    // Only statements that can read reservation rows count.
    $mentioning = $queries->filter(fn ($q) => (str_contains($q, 'wish_reservations') || str_contains($q, 'wish_contributions')) && ! str_contains($q, 'where 0 = 1'));
    expect($mentioning->all())->toBe([]);
})->with(['memberA', 'memberB']);

it('answers every giving write on a gifts-group wish identically with and without a reservation', function () {
    $ctx = WishlistPrivacy::groupScenario(GroupMode::Gifts);

    $without = grpPrivRunWrites($this, $ctx, $ctx['memberA']);
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);
    $with = grpPrivRunWrites($this, $ctx, $ctx['memberA']);

    expect($with)->toEqual($without)
        ->and(WishReservation::query()->count())->toBe(1);
});

it('shows a shared-group member the organizer and contributor names and amounts', function () {
    $ctx = WishlistPrivacy::groupScenario(GroupMode::Shared);
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);

    $this->app['auth']->forgetGuards();
    $response = $this->actingAs($ctx['memberA'])->get('/wishlist?group='.$ctx['group']->id, [
        'X-Inertia' => 'true', 'X-Inertia-Version' => WishlistPrivacy::inertiaVersion(),
    ])->assertOk();
    $body = WishlistPrivacy::scannable((string) $response->getContent());

    expect($body)->toContain(WishlistPrivacy::CANARY_NAME)
        ->and($body)->toContain('Gita Giver')
        ->and($body)->toContain('7777');
});

it('keeps a shared-group non-member out of the list, the image and every giving route', function () {
    $ctx = WishlistPrivacy::groupScenario(GroupMode::Shared);
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);
    $outsider = $ctx['giver2'];

    $this->app['auth']->forgetGuards();
    $this->actingAs($outsider)->get('/wishlist?group='.$ctx['group']->id)->assertForbidden();
    $this->actingAs($outsider)->get('/wishlist?group='.$ctx['group']->id.'&wish='.$ctx['wish']->id)->assertForbidden();
    $this->actingAs($outsider)->get(route('wishlist.images.show', ['wish' => $ctx['wish']->id, 'variant' => 'thumb'], false))->assertNotFound();

    grpPrivRunWrites($this, $ctx, $outsider, 403);
    expect(WishReservation::query()->count())->toBe(1);
});

it('shows a gifts-group outsider the giver view with the reservation', function () {
    $ctx = WishlistPrivacy::groupScenario(GroupMode::Gifts);
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);

    $this->app['auth']->forgetGuards();
    $response = $this->actingAs($ctx['giver'])->get('/wishlist?group='.$ctx['group']->id, [
        'X-Inertia' => 'true', 'X-Inertia-Version' => WishlistPrivacy::inertiaVersion(),
    ])->assertOk();

    $wish = collect($response->json('props.list.wishes'))->firstWhere('id', $ctx['wish']->id);
    expect($response->json('props.list.viewer_is_recipient'))->toBeFalse()
        ->and($wish['reservation']['organizer']['name'])->toBe(WishlistPrivacy::CANARY_NAME);
});
