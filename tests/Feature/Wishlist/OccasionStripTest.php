<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\WishOccasion;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\Support\WishlistPrivacy;

afterEach(fn () => Carbon::setTestNow());

function stripAt(string $datetime): void
{
    Carbon::setTestNow(CarbonImmutable::parse($datetime, 'Europe/Bratislava'));
}

function stripFor(User $viewer, ?User $owner = null)
{
    $url = '/wishlist'.($owner ? '?member='.$owner->id : '');

    return test()->actingAs($viewer)->get($url)->assertOk()->viewData('page')['props']['occasions'];
}

it('gives at most 3 occasions within 60 days sorted by date', function () {
    stripAt('2027-03-01 08:00');
    $ctx = WishlistPrivacy::scenario();
    foreach (['1990-03-05', '1991-03-10', '1992-03-20', '1993-03-25'] as $i => $birth) {
        User::factory()->withPreset(Preset::Parent)->create(['birth_date' => $birth]);
    }
    User::factory()->withPreset(Preset::Parent)->create(['birth_date' => '1990-12-01']);

    $strip = stripFor($ctx['owner']);

    expect($strip)->toHaveCount(3)
        ->and(array_column($strip, 'date'))->toBe(['2027-03-05', '2027-03-10', '2027-03-20'])
        ->and($strip[0])->toHaveKeys(['key', 'type', 'owner', 'title', 'date', 'days_away'])
        ->and($strip[0]['days_away'])->toBe(4);
});

it('is identical for owner, manager and giver views and never carries a birth year', function () {
    stripAt('2027-03-01 08:00');
    $ctx = WishlistPrivacy::scenario();
    User::factory()->withPreset(Preset::Parent)->create(['birth_date' => '1987-03-05']);
    WishOccasion::factory()->create(['owner_id' => $ctx['owner']->id, 'title' => 'Housewarming', 'month' => 3, 'day' => 9, 'year' => null]);

    $own = stripFor($ctx['owner']);

    expect(stripFor($ctx['manager']))->toEqual($own)
        ->and(stripFor($ctx['giver'], $ctx['owner']))->toEqual($own)
        ->and(json_encode($own))->not->toContain('1987');
});

it('does not change when the owner wish is reserved', function () {
    stripAt('2027-03-01 08:00');
    $ctx = WishlistPrivacy::scenario();
    User::factory()->withPreset(Preset::Parent)->create(['birth_date' => '1987-03-05']);

    $before = stripFor($ctx['owner']);
    WishlistPrivacy::seedReservationWithCoBuy($ctx['wish'], $ctx['giver'], $ctx['giver2']);

    expect(stripFor($ctx['owner']))->toEqual($before);
});
