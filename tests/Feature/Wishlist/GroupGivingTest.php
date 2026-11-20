<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishlistGroup;
use App\Models\WishReservation;
use App\Wishlist\Giving\GivingGuard;
use App\Wishlist\Giving\ReservationService;
use App\Wishlist\ViewerContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

function grpGiveRoutes(Wish $wish): array
{
    $base = "/wishlist/wishes/{$wish->id}/reservation";

    return [
        ['post', $base], ['delete', $base], ['post', $base.'/cobuy'], ['delete', $base.'/cobuy'],
        ['put', $base.'/contribution'], ['delete', $base.'/contribution'],
    ];
}

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->a = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Anna']);
    $this->b = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Boris']);
    $this->c = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Cyril']);
    $this->gifts = WishlistGroup::factory()->withMembers([$this->a, $this->b])->create(['name' => 'Couple']);
    $this->shared = WishlistGroup::factory()->shared()->withMembers([$this->a, $this->b])->create(['name' => 'Home']);
    $this->giftWish = Wish::factory()->forGroup($this->gifts)->create(['title' => 'Sofa', 'price_minor' => 50000]);
    $this->sharedWish = Wish::factory()->forGroup($this->shared)->create(['title' => 'Lamp']);
});

describe('giving rules', function () {
    it('lets an outsider reserve on a gifts group and keeps members out identically', function () {
        $url = "/wishlist/wishes/{$this->giftWish->id}/reservation";

        $before = [];
        foreach (grpGiveRoutes($this->giftWish) as [$m, $u]) {
            $before[] = $this->actingAs($this->a)->{$m}($u)->status();
        }
        $this->actingAs($this->c)->post($url)->assertStatus(303);
        expect(WishReservation::query()->where('wish_id', $this->giftWish->id)->sole()->user_id)->toBe($this->c->id);

        foreach ([$this->a, $this->b] as $member) {
            $after = [];
            foreach (grpGiveRoutes($this->giftWish) as [$m, $u]) {
                $after[] = $this->actingAs($member)->{$m}($u)->status();
            }
            expect($after)->toBe([403, 403, 403, 403, 403, 403]);
        }
        expect($before)->toBe([403, 403, 403, 403, 403, 403])
            ->and(WishReservation::count())->toBe(1);
    });

    it('gives the outsider the full co-buy flow on a gifts group', function () {
        $this->actingAs($this->c)->post("/wishlist/wishes/{$this->giftWish->id}/reservation")->assertStatus(303);
        $this->actingAs($this->c)->post("/wishlist/wishes/{$this->giftWish->id}/reservation/cobuy")->assertStatus(303);
        expect(WishReservation::query()->sole()->cobuy_state)->toBe('open');
        $this->actingAs($this->c)->delete("/wishlist/wishes/{$this->giftWish->id}/reservation")->assertStatus(303);
        expect(WishReservation::count())->toBe(0);
    });

    it('works for shared members and refuses non-members', function () {
        $base = "/wishlist/wishes/{$this->sharedWish->id}/reservation";
        $this->actingAs($this->a)->post($base)->assertStatus(303);
        $this->actingAs($this->a)->post($base.'/cobuy')->assertStatus(303);
        $this->actingAs($this->b)->putJson($base.'/contribution', ['amount' => '10'])->assertStatus(303);
        expect(WishContribution::query()->where('user_id', $this->b->id)->exists())->toBeTrue();

        foreach (grpGiveRoutes($this->sharedWish) as [$m, $u]) {
            $this->actingAs($this->c)->{$m}($u)->assertForbidden();
        }
        $this->actingAs($this->a)->delete($base)->assertStatus(303);
        expect(WishReservation::count())->toBe(0);
    });

    it('keeps a gifts member reserving on a personal list where they are a giver', function () {
        $personal = Wish::factory()->create(['owner_id' => $this->c->id]);
        $this->actingAs($this->a)->post("/wishlist/wishes/{$personal->id}/reservation")->assertStatus(303);
        expect(WishReservation::query()->where('wish_id', $personal->id)->count())->toBe(1);
    });

    it('re-checks membership under the lock when the context is stale', function () {
        $guard = app(GivingGuard::class);

        // C joins the gifts group after having passed authorize: the in-transaction check refuses.
        DB::table('wishlist_group_members')->insert(['group_id' => $this->gifts->id, 'user_id' => $this->c->id]);
        expect(fn () => DB::transaction(fn () => $guard->assertStillGiverOfWish($this->c, $this->giftWish)))->toThrow(HttpException::class);
        expect(fn () => app(ReservationService::class)->reserve($this->c, $this->giftWish))->toThrow(LogicException::class);
        expect(WishReservation::count())->toBe(0);

        // A is removed from the shared group before the insert.
        DB::table('wishlist_group_members')->where('group_id', $this->shared->id)->where('user_id', $this->a->id)->delete();
        expect(fn () => DB::transaction(fn () => $guard->assertStillGiverOfWish($this->a, $this->sharedWish)))->toThrow(HttpException::class);
        expect(fn () => $this->actingAs($this->a)->post("/wishlist/wishes/{$this->sharedWish->id}/reservation")->assertForbidden())->not->toThrow(Throwable::class);
        expect(WishReservation::count())->toBe(0);
    });

    it('throws a LogicException for a gifts member in assertGiverOfWish', function () {
        expect(fn () => app(GivingGuard::class)->assertGiverOfWish(ViewerContext::for($this->a), $this->giftWish))->toThrow(LogicException::class);
        app(GivingGuard::class)->assertGiverOfWish(ViewerContext::for($this->c), $this->giftWish);
        expect(true)->toBeTrue();
    });
});

describe('page', function () {
    it('shows a gifts member the reservation-blind view', function () {
        WishReservation::factory()->create(['wish_id' => $this->giftWish->id, 'user_id' => $this->c->id]);
        $this->actingAs($this->a)->get('/wishlist?group='.$this->gifts->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Wishlist/Index')
                ->where('list.kind', 'group')->where('list.viewer_is_recipient', true)
                ->missing('list.wishes.0.reservation')
                ->has('groups', 2)->has('group_candidates'));
    });

    it('shows an outsider the giver view of a gifts group', function () {
        $res = WishReservation::factory()->create(['wish_id' => $this->giftWish->id, 'user_id' => $this->c->id]);
        $this->actingAs($this->c)->get('/wishlist?group='.$this->gifts->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('list.viewer_is_recipient', false)
                ->where('list.viewer_can_reserve', true)
                ->where('list.wishes.0.reservation.organizer.name', 'Cyril')
                ->missing('list.received'));
        $res->delete();
        $this->actingAs($this->c)->get('/wishlist?group='.$this->gifts->id)
            ->assertInertia(fn (Assert $p) => $p->where('list.wishes.0.reservation', null));
    });

    it('shows shared members names and refuses non-members', function () {
        WishReservation::factory()->cobuyOpen()->create(['wish_id' => $this->sharedWish->id, 'user_id' => $this->a->id]);
        $this->actingAs($this->b)->get('/wishlist?group='.$this->shared->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('list.can_edit', true)
                ->where('list.wishes.0.reservation.organizer.name', 'Anna')->has('list.received'));
        $this->actingAs($this->c)->get('/wishlist?group='.$this->shared->id)->assertForbidden();
    });

    it('refuses bad group parameters', function () {
        foreach (['999999', 'abc', '12345678901', '1.5'] as $bad) {
            $this->actingAs($this->a)->get('/wishlist?group='.$bad)->assertForbidden();
        }
        $this->actingAs($this->a)->get('/wishlist?group='.$this->gifts->id.'&member='.$this->a->id)->assertForbidden();
    });

    it('adds groups and candidates to personal renders, candidates only for editors', function () {
        $this->actingAs($this->c)->get('/wishlist')->assertInertia(fn (Assert $p) => $p->has('groups', 1)->where('groups.0.is_member', false)->where('group_candidates', fn ($c) => count($c) >= 1));
        $viewOnly = User::factory()->withGrant(Module::Wishlist, AccessLevel::View)->create();
        $this->actingAs($viewOnly)->get('/wishlist')->assertInertia(fn (Assert $p) => $p->where('group_candidates', []));
    });
});

describe('shopping', function () {
    it('lists group reservations under the group name and hides stale ones', function () {
        WishReservation::factory()->create(['wish_id' => $this->giftWish->id, 'user_id' => $this->c->id]);
        $this->actingAs($this->c)->getJson('/wishlist/shopping')->assertOk()
            ->assertJsonPath('groups.0.group.name', 'Couple')
            ->assertJsonPath('groups.0.owner.id', null)
            ->assertJsonPath('groups.0.items.0.title', 'Sofa');

        // A member of the gifts group with a stale row sees nothing for that group.
        WishReservation::factory()->create(['wish_id' => Wish::factory()->forGroup($this->gifts)->create()->id, 'user_id' => $this->a->id]);
        $this->actingAs($this->a)->getJson('/wishlist/shopping')->assertOk()->assertJsonCount(0, 'groups');

        // A removed shared member with a stale row sees nothing.
        WishReservation::factory()->create(['wish_id' => $this->sharedWish->id, 'user_id' => $this->c->id]);
        $this->actingAs($this->c)->getJson('/wishlist/shopping')->assertJsonCount(1, 'groups');
    });

    it('keeps personal sections unchanged and lists them before groups', function () {
        $personal = Wish::factory()->create(['owner_id' => $this->b->id, 'title' => 'Book']);
        WishReservation::factory()->create(['wish_id' => $personal->id, 'user_id' => $this->c->id]);
        WishReservation::factory()->create(['wish_id' => $this->giftWish->id, 'user_id' => $this->c->id]);
        $json = $this->actingAs($this->c)->getJson('/wishlist/shopping')->assertOk()->json('groups');
        expect($json)->toHaveCount(2)
            ->and(array_keys($json[0]))->toBe(['owner', 'items'])
            ->and($json[0]['owner']['id'])->toBe($this->b->id)
            ->and($json[1]['group']['id'])->toBe($this->gifts->id);
    });

    it('shows a shared member their reservation under the group', function () {
        WishReservation::factory()->create(['wish_id' => $this->sharedWish->id, 'user_id' => $this->a->id]);
        $this->actingAs($this->a)->getJson('/wishlist/shopping')->assertJsonPath('groups.0.group.name', 'Home');
    });
});
