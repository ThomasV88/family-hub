<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\ModulePermission;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishlistManager;
use App\Models\WishReservation;
use App\Wishlist\Giving\GiverListPage;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Petra']);
    $this->child = User::factory()->withPreset(Preset::Child)->create(['name' => 'Adam']);
    $this->grand = User::factory()->withPreset(Preset::Grandparent)->create(['name' => 'Babka']);
    $this->wish = Wish::factory()->create(['owner_id' => $this->child->id, 'title' => 'Bike']);
});

it('lets a giver reserve with one reservation, an organizer contribution and no side effects', function () {
    $updatedAt = $this->wish->fresh()->updated_at;
    $audit = DB::table('audit_logs')->count();
    $this->travel(5)->minutes();

    $this->actingAs($this->parent)->post('/wishlist/wishes/'.$this->wish->id.'/reservation')
        ->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.reserved');

    $reservation = WishReservation::query()->sole();
    expect($reservation->user_id)->toBe($this->parent->id)
        ->and($reservation->cobuy_state)->toBe('none')
        ->and(WishContribution::query()->sole()->amount_minor)->toBeNull()
        ->and($this->wish->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(DB::table('audit_logs')->count())->toBe($audit);
});

it('gives the second giver a conflict with the first buyer name and changes nothing', function () {
    WishReservation::factory()->create(['wish_id' => $this->wish->id, 'user_id' => $this->parent->id]);

    $this->actingAs($this->grand)->post('/wishlist/wishes/'.$this->wish->id.'/reservation')
        ->assertStatus(303)
        ->assertSessionHas('flash_data.reservation_conflict', ['wish_id' => $this->wish->id, 'name' => 'Petra']);

    expect(WishReservation::query()->count())->toBe(1)->and(WishContribution::query()->count())->toBe(1);
});

it('refuses owner, manager, view-only and guest-preset callers with a 403 that does not depend on a reservation', function () {
    $manager = User::factory()->withPreset(Preset::Parent)->create();
    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $manager->id]);
    $viewOnly = User::factory()->withPreset(Preset::Grandparent)->create();
    ModulePermission::query()->where('user_id', $viewOnly->id)->where('module', Module::Wishlist->value)
        ->update(['level' => AccessLevel::View->value]);
    $guest = User::factory()->withPreset(Preset::Guest)->create();

    $bodies = [];
    foreach ([false, true] as $reserved) {
        if ($reserved) {
            WishReservation::factory()->create(['wish_id' => $this->wish->id, 'user_id' => $this->parent->id]);
        }
        foreach (['child' => $this->child, 'manager' => $manager, 'viewOnly' => $viewOnly, 'guest' => $guest] as $label => $user) {
            foreach (['post', 'delete'] as $verb) {
                $this->app['auth']->forgetGuards();
                $response = $this->actingAs($user)->$verb('/wishlist/wishes/'.$this->wish->id.'/reservation');
                $response->assertForbidden();
                $bodies["$label $verb"][] = $response->getContent();
            }
        }
    }
    foreach ($bodies as $key => $pair) {
        expect($pair[0])->toBe($pair[1], $key);
    }
    expect(WishReservation::query()->count())->toBe(1);
});

it('answers 409 for a received wish and 404 for cancelling nothing', function () {
    $received = Wish::factory()->received()->create(['owner_id' => $this->child->id]);

    $this->actingAs($this->parent)->post('/wishlist/wishes/'.$received->id.'/reservation')->assertStatus(409);
    $this->actingAs($this->parent)->delete('/wishlist/wishes/'.$this->wish->id.'/reservation')->assertNotFound();
});

it('lets only the organizer cancel and removes the contributions with it', function () {
    $reservation = WishReservation::factory()->cobuyOpen()->create(['wish_id' => $this->wish->id, 'user_id' => $this->parent->id]);
    WishContribution::factory()->create(['reservation_id' => $reservation->id, 'user_id' => $this->grand->id, 'amount_minor' => 500]);

    $this->actingAs($this->grand)->delete('/wishlist/wishes/'.$this->wish->id.'/reservation')->assertForbidden();
    expect(WishReservation::query()->count())->toBe(1);

    $this->actingAs($this->parent)->delete('/wishlist/wishes/'.$this->wish->id.'/reservation')
        ->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.unreserved');
    expect(WishReservation::query()->count())->toBe(0)->and(WishContribution::query()->count())->toBe(0);
});

it('shows givers the reservation shape, available wishes first, and viewer_can_reserve', function () {
    $free = Wish::factory()->create(['owner_id' => $this->child->id, 'title' => 'Free', 'priority' => 0]);
    $reservation = WishReservation::factory()->create(['wish_id' => $this->wish->id, 'user_id' => $this->parent->id]);
    WishContribution::query()->where('reservation_id', $reservation->id)->update(['amount_minor' => 1000]);

    $this->actingAs($this->grand)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.viewer_is_recipient', false)->where('list.viewer_can_reserve', true)
        ->where('list.wishes.0.title', 'Free')->where('list.wishes.0.reservation', null)
        ->where('list.wishes.1.title', 'Bike')
        ->where('list.wishes.1.reservation.organizer', ['id' => $this->parent->id, 'name' => 'Petra'])
        ->where('list.wishes.1.reservation.is_mine', false)
        ->where('list.wishes.1.reservation.cobuy_state', 'none')
        ->where('list.wishes.1.reservation.pledged', '10.00')
        ->where('list.wishes.1.reservation.people', 1)
        ->where('list.wishes.1.reservation.i_joined', false)
        ->where('list.wishes.1.reservation.my_amount', null)
        ->where('list.wishes.1.reservation.contributors.0', ['id' => $this->parent->id, 'name' => 'Petra', 'amount' => '10.00', 'is_organizer' => true, 'is_me' => false]));

    $this->actingAs($this->parent)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.wishes.1.reservation.is_mine', true)->where('list.wishes.1.reservation.contributors.0.is_me', true));
    expect($free->id)->not->toBeNull();
});

it('shows reservations read-only to a view-only member', function () {
    WishReservation::factory()->create(['wish_id' => $this->wish->id, 'user_id' => $this->parent->id]);
    $viewOnly = User::factory()->withPreset(Preset::Grandparent)->create();
    ModulePermission::query()->where('user_id', $viewOnly->id)->where('module', Module::Wishlist->value)
        ->update(['level' => AccessLevel::View->value]);

    $this->actingAs($viewOnly)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.viewer_can_reserve', false)->where('list.wishes.0.reservation.organizer.name', 'Petra'));
    $this->actingAs($viewOnly)->post('/wishlist/wishes/'.$this->wish->id.'/reservation')->assertForbidden();
});

it('gives an admin with a wishlist edit grant the giver page and lets them reserve', function () {
    $admin = User::factory()->admin()->withPreset(Preset::Parent)->create(['name' => 'Admin']);

    $this->actingAs($admin)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.viewer_is_recipient', false)->where('list.viewer_can_reserve', true)->has('list.wishes.0.reservation'));
    $this->actingAs($admin)->post('/wishlist/wishes/'.$this->wish->id.'/reservation')->assertStatus(303);
    expect(WishReservation::query()->sole()->user_id)->toBe($admin->id);
});

it('never exposes reservation props on the recipient branch', function () {
    WishReservation::factory()->create(['wish_id' => $this->wish->id, 'user_id' => $this->parent->id]);

    $this->actingAs($this->child)->get('/wishlist')->assertInertia(fn (Assert $p) => $p
        ->missing('list.viewer_can_reserve')->missing('list.wishes.0.reservation'));
});

it('throws when the giver page is rendered for a recipient-side viewer', function () {
    $request = Request::create('/wishlist');
    app(GiverListPage::class)->render($this->child, $this->child, $request);
})->throws(LogicException::class);
