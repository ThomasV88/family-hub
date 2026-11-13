<?php

use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishReservation;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\WishlistPrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Queue::fake();
});

/**
 * Performs $call against a reserved wish and an unreserved twin owned by the same person and returns
 * both outcomes with the wish id normalised away.
 *
 * @return array{reserved: array<string, mixed>, free: array<string, mixed>}
 */
function ownerActionOutcomes(object $test, string $side, Closure $prepare, Closure $call): array
{
    $ctx = WishlistPrivacy::scenario();
    $viewer = $ctx[$side];
    $results = [];
    foreach (['reserved' => $ctx['wish'], 'free' => $ctx['twin']] as $label => $wish) {
        $prepare($wish);
        if ($label === 'reserved') {
            WishlistPrivacy::seedReservationWithCoBuy($wish, $ctx['giver'], $ctx['giver2']);
        }
        app('auth')->forgetGuards();
        $test->flushSession();
        $response = $call($test->actingAs($viewer)->withHeaders(['Referer' => url('/wishlist?member='.$ctx['owner']->id)]), $wish);
        $results[$label] = [
            'status' => $response->getStatusCode(),
            'location' => preg_replace('/\d+$/', '{id}', (string) $response->headers->get('Location')),
            'toast' => session('toast'),
        ];
    }

    return $results + ['ctx' => $ctx];
}

it('treats a reserved wish exactly like an unreserved one for every owner action', function (string $side, string $action) {
    $setup = [
        'update' => [fn () => null, fn ($t, Wish $w) => $t->patch('/wishlist/wishes/'.$w->id, ['title' => 'Renamed'])],
        'delete' => [fn () => null, fn ($t, Wish $w) => $t->delete('/wishlist/wishes/'.$w->id)],
        'received' => [fn () => null, fn ($t, Wish $w) => $t->post('/wishlist/wishes/'.$w->id.'/received')],
        'unreceive' => [
            fn (Wish $w) => $w->forceFill(['received_at' => now()])->save(),
            fn ($t, Wish $w) => $t->delete('/wishlist/wishes/'.$w->id.'/received'),
        ],
    ];
    if (Route::has('wishlist.images.destroy')) {
        $setup['image-remove'] = [fn () => null, fn ($t, Wish $w) => $t->delete('/wishlist/wishes/'.$w->id.'/image')];
    }
    if (Route::has('wishlist.images.fetch')) {
        $setup['image-fetch'] = [fn () => null, fn ($t, Wish $w) => $t->post('/wishlist/wishes/'.$w->id.'/image/fetch')];
    }
    if (! isset($setup[$action])) {
        $this->markTestSkipped("route for $action does not exist yet");
    }

    [$prepare, $call] = $setup[$action];
    $out = ownerActionOutcomes($this, $side, $prepare, fn ($t, $w) => $call($t, $w));

    expect($out['reserved'])->toEqual($out['free']);
    // The action itself must have been allowed (not a shared 403 hiding a difference).
    expect($out['reserved']['status'])->toBeIn([200, 302, 303, 422]);
})->with(['owner', 'manager'])->with(['update', 'delete', 'received', 'unreceive', 'image-remove', 'image-fetch']);

it('removes reservation rows by cascade when a reserved wish is deleted', function () {
    $ctx = WishlistPrivacy::scenario();
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);
    expect(WishReservation::query()->count())->toBe(1)->and(WishContribution::query()->count())->toBe(2);

    $this->actingAs($ctx['owner'])->delete('/wishlist/wishes/'.$ctx['wish']->id)->assertStatus(303);

    expect(WishReservation::query()->count())->toBe(0)->and(WishContribution::query()->count())->toBe(0);
});

it('leaves reservation rows in place when a reserved wish is marked received', function () {
    $ctx = WishlistPrivacy::scenario();
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);

    $this->actingAs($ctx['owner'])->post('/wishlist/wishes/'.$ctx['wish']->id.'/received')->assertStatus(303);

    expect($ctx['wish']->fresh()->received_at)->not->toBeNull()
        ->and(WishReservation::query()->count())->toBe(1)
        ->and(WishContribution::query()->count())->toBe(2);
});
