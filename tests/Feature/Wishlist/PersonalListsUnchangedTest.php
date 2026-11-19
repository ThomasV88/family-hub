<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistGroup;
use App\Models\WishlistManager;
use App\Models\WishReservation;
use App\Wishlist\Giving\ManagerReservationPurger;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Arr;
use Tests\Support\WishlistPrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/** The `list` and `selected` props of a list page (the top-level groups props are expected to differ). */
function persUnchProps($test, User $viewer, int $owner, string $query = ''): array
{
    app('auth')->forgetGuards();
    $test->flushSession();
    $response = $test->actingAs($viewer)->get('/wishlist?member='.$owner.$query, [
        'X-Inertia' => 'true', 'X-Inertia-Version' => WishlistPrivacy::inertiaVersion(),
    ])->assertOk();

    return Arr::only($response->json('props'), ['list', 'selected']);
}

/** @return array<string, int> status of the giving writes on one wish for one viewer */
function persUnchGivingStatuses($test, User $viewer, Wish $wish): array
{
    $out = [];
    foreach ([
        'reserve' => ['post', ''], 'cancel' => ['delete', ''], 'cobuy' => ['post', '/cobuy'], 'contribute' => ['put', '/contribution'],
    ] as $name => [$method, $suffix]) {
        app('auth')->forgetGuards();
        $test->flushSession();
        $out[$name] = $test->actingAs($viewer)->call($method, "/wishlist/wishes/{$wish->id}/reservation{$suffix}", ['amount' => '12,50'])->getStatusCode();
    }

    return $out;
}

/** Puts owner and manager into a gifts group, giver into a shared group, adds group wishes and a group reservation. */
function persUnchJoinGroups(array $ctx): void
{
    $gifts = WishlistGroup::factory()->withMembers([$ctx['owner'], $ctx['manager']])->create(['name' => 'Gifts group']);
    $shared = WishlistGroup::factory()->shared()->withMembers([$ctx['giver'], $ctx['giver2']])->create(['name' => 'Shared group']);
    Wish::factory()->forGroup($gifts)->create(['title' => 'Group gift']);
    $sharedWish = Wish::factory()->forGroup($shared)->create(['title' => 'Group shared']);
    WishReservation::factory()->create(['wish_id' => $sharedWish->id, 'user_id' => $ctx['giver']->id]);
}

it('renders the same list and selected props for personal lists whether or not their people belong to groups', function () {
    $ctx = WishlistPrivacy::scenario();
    $owner = $ctx['owner']->id;
    $viewers = ['owner' => $ctx['owner'], 'manager' => $ctx['manager'], 'giver' => $ctx['giver']];

    $before = [];
    foreach ($viewers as $side => $viewer) {
        $before[$side] = persUnchProps($this, $viewer, $owner);
        $before[$side.'-wish'] = persUnchProps($this, $viewer, $owner, '&wish='.$ctx['wish']->id);
    }

    persUnchJoinGroups($ctx);

    foreach ($viewers as $side => $viewer) {
        expect(persUnchProps($this, $viewer, $owner))->toEqual($before[$side])
            ->and(persUnchProps($this, $viewer, $owner, '&wish='.$ctx['wish']->id))->toEqual($before[$side.'-wish']);
    }
    expect($before['owner']['list']['wishes'])->toHaveCount(2)
        ->and($before['giver']['list']['viewer_is_recipient'])->toBeFalse();
});

it('answers the giving routes on a personal wish the same before and after the group setup', function () {
    $ctx = WishlistPrivacy::scenario();

    $before = [
        'owner' => persUnchGivingStatuses($this, $ctx['owner'], $ctx['wish']),
        'manager' => persUnchGivingStatuses($this, $ctx['manager'], $ctx['wish']),
    ];
    $reserveBefore = $this->actingAs($ctx['giver'])->post("/wishlist/wishes/{$ctx['wish']->id}/reservation")->getStatusCode();

    persUnchJoinGroups($ctx);

    expect(persUnchGivingStatuses($this, $ctx['owner'], $ctx['twin']))->toBe($before['owner'])
        ->and(persUnchGivingStatuses($this, $ctx['manager'], $ctx['twin']))->toBe($before['manager'])
        ->and($before['owner']['reserve'])->toBe(403)
        ->and($before['manager']['reserve'])->toBe(403);

    app('auth')->forgetGuards();
    expect($this->actingAs($ctx['giver'])->post("/wishlist/wishes/{$ctx['twin']->id}/reservation")->getStatusCode())->toBe($reserveBefore)
        ->and($reserveBefore)->toBe(303);
});

it('keeps a managing parent recipient-side on the child list and out of group wishes after joining a gifts group', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Petra']);
    $child = User::factory()->child()->withPreset(Preset::Child)->create(['name' => 'Ema']);
    $giver = User::factory()->withPreset(Preset::Grandparent)->create();
    WishlistManager::factory()->create(['owner_id' => $child->id, 'manager_id' => $parent->id]);
    Wish::factory()->create(['owner_id' => $child->id, 'title' => 'Kid wish']);

    $group = WishlistGroup::factory()->withMembers([$parent, $giver])->create();
    Wish::factory()->forGroup($group)->create(['title' => 'Couple sofa']);

    $props = persUnchProps($this, $parent, $child->id);
    expect($props['list']['viewer_is_recipient'])->toBeTrue()
        ->and(collect($props['list']['wishes'])->pluck('title')->all())->toBe(['Kid wish'])
        ->and($props['list']['wishes'][0])->not->toHaveKey('reservation');

    $this->actingAs($parent)->post('/wishlist/wishes', ['title' => 'Lego', 'owner_id' => $child->id])
        ->assertStatus(303)->assertRedirect('/wishlist?member='.$child->id);
    $created = Wish::query()->where('title', 'Lego')->sole();
    expect($created->owner_id)->toBe($child->id)->and($created->group_id)->toBeNull()
        ->and(collect(persUnchProps($this, $parent, $child->id)['list']['wishes'])->pluck('title')->all())->toContain('Lego', 'Kid wish')
        ->not->toContain('Couple sofa');
});

it('still purges a new manager reservations on the managed list when that manager is also a group member', function () {
    $owner = User::factory()->withPreset(Preset::Child)->create();
    $other = User::factory()->withPreset(Preset::Child)->create();
    $manager = User::factory()->withPreset(Preset::Parent)->create();
    $partner = User::factory()->withPreset(Preset::Parent)->create();
    $group = WishlistGroup::factory()->withMembers([$manager, $partner])->create();
    Wish::factory()->forGroup($group)->create();

    $mine = Wish::factory()->create(['owner_id' => $owner->id]);
    $elsewhere = Wish::factory()->create(['owner_id' => $other->id]);
    WishReservation::factory()->create(['wish_id' => $mine->id, 'user_id' => $manager->id]);
    WishReservation::factory()->create(['wish_id' => $elsewhere->id, 'user_id' => $manager->id]);

    app(ManagerReservationPurger::class)->purge($owner->id, $manager->id);

    expect(WishReservation::query()->where('wish_id', $mine->id)->exists())->toBeFalse()
        ->and(WishReservation::query()->where('wish_id', $elsewhere->id)->exists())->toBeTrue();
});

it('does not change the personal wish count of an owner by creating group wishes', function () {
    $ctx = WishlistPrivacy::scenario();
    $count = fn () => Wish::query()->where('owner_id', $ctx['owner']->id)->count();
    $before = $count();

    $group = WishlistGroup::factory()->withMembers([$ctx['owner'], $ctx['manager']])->create();
    Wish::factory()->forGroup($group)->create(['title' => 'Factory group wish']);
    $this->actingAs($ctx['owner'])->post('/wishlist/wishes', ['title' => 'Posted group wish', 'group_id' => $group->id])->assertStatus(303);

    expect(Wish::query()->where('group_id', $group->id)->count())->toBe(2)
        ->and(Wish::query()->where('group_id', $group->id)->whereNotNull('owner_id')->count())->toBe(0)
        ->and($count())->toBe($before);
});
