<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishReservation;
use App\Wishlist\Giving\ReservationRetention;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Petra']);
    $this->child = User::factory()->withPreset(Preset::Child)->create(['name' => 'Adam']);
    $this->grand = User::factory()->withPreset(Preset::Grandparent)->create(['name' => 'Babka']);
    $this->wish = Wish::factory()->create(['owner_id' => $this->child->id, 'title' => 'Bike', 'price_minor' => 20000]);
    $this->base = '/wishlist/wishes/'.$this->wish->id.'/reservation';
    $this->reserve = fn (string $state = 'none') => WishReservation::factory()->create(['wish_id' => $this->wish->id, 'user_id' => $this->parent->id, 'cobuy_state' => $state]);
});

it('walks the organizer through open, close and reopen and refuses everyone else', function () {
    ($this->reserve)();

    $this->actingAs($this->grand)->post($this->base.'/cobuy')->assertForbidden();
    $this->actingAs($this->parent)->post($this->base.'/cobuy')->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.cobuy_open');
    expect(WishReservation::query()->sole()->cobuy_state)->toBe('open');

    $this->actingAs($this->parent)->post($this->base.'/cobuy')->assertStatus(409);
    $this->actingAs($this->grand)->delete($this->base.'/cobuy')->assertForbidden();
    $this->actingAs($this->parent)->delete($this->base.'/cobuy')->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.cobuy_closed');
    expect(WishReservation::query()->sole()->cobuy_state)->toBe('closed');

    $this->actingAs($this->parent)->delete($this->base.'/cobuy')->assertStatus(409);
    $this->actingAs($this->parent)->post($this->base.'/cobuy')->assertStatus(303);
    expect(WishReservation::query()->sole()->cobuy_state)->toBe('open');
});

it('lets a giver join with or without an amount, change it and leave', function () {
    ($this->reserve)('open');

    $this->actingAs($this->grand)->putJson($this->base.'/contribution', ['amount' => '25,50'])->assertRedirect();
    expect(WishContribution::query()->where('user_id', $this->grand->id)->sole()->amount_minor)->toBe(2550);

    $this->actingAs($this->grand)->put($this->base.'/contribution', ['amount' => '10'])
        ->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.joined');
    expect(WishContribution::query()->where('user_id', $this->grand->id)->sole()->amount_minor)->toBe(1000);

    $this->actingAs($this->grand)->put($this->base.'/contribution', [])->assertStatus(303);
    expect(WishContribution::query()->where('user_id', $this->grand->id)->sole()->amount_minor)->toBeNull();

    $this->actingAs($this->grand)->delete($this->base.'/contribution')
        ->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.left');
    expect(WishContribution::query()->where('user_id', $this->grand->id)->exists())->toBeFalse();
    $this->actingAs($this->grand)->delete($this->base.'/contribution')->assertNotFound();
});

it('refuses joining unless co-buy is open, and the organizer leaving, but lets the organizer set their share', function () {
    foreach (['none', 'closed'] as $state) {
        WishReservation::query()->delete();
        ($this->reserve)($state);
        $this->actingAs($this->grand)->put($this->base.'/contribution', ['amount' => '5'])->assertStatus(409);
    }

    WishReservation::query()->delete();
    ($this->reserve)('open');
    $this->actingAs($this->parent)->delete($this->base.'/contribution')->assertStatus(409);
    $this->actingAs($this->parent)->put($this->base.'/contribution', ['amount' => '40'])->assertStatus(303);
    expect(WishContribution::query()->where('user_id', $this->parent->id)->sole()->amount_minor)->toBe(4000);
});

it('rejects invalid amounts with the share_invalid message', function (string $amount) {
    ($this->reserve)('open');

    $this->actingAs($this->grand)->putJson($this->base.'/contribution', ['amount' => $amount])
        ->assertStatus(422)->assertJsonPath('errors.amount.0', 'wishlist.error.share_invalid');
    expect(WishContribution::query()->where('user_id', $this->grand->id)->exists())->toBeFalse();
})->with(['0', '-1', '1.234', 'abc', '0,00']);

it('shows pledged sum, people and per-contributor amounts to every giver and cancels everyone together', function () {
    $reservation = ($this->reserve)('open');
    WishContribution::query()->where('reservation_id', $reservation->id)->update(['amount_minor' => 5000]);
    WishContribution::factory()->create(['reservation_id' => $reservation->id, 'user_id' => $this->grand->id, 'amount_minor' => 2550]);

    $this->actingAs($this->grand)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.wishes.0.reservation.pledged', '75.50')
        ->where('list.wishes.0.reservation.people', 2)
        ->where('list.wishes.0.reservation.i_joined', true)
        ->where('list.wishes.0.reservation.my_amount', '25.50')
        ->where('list.wishes.0.reservation.contributors.0.is_organizer', true)
        ->where('list.wishes.0.reservation.contributors.1.amount', '25.50'));

    $updatedAt = $this->wish->fresh()->updated_at;
    $audit = DB::table('audit_logs')->count();
    $this->actingAs($this->parent)->delete($this->base)->assertStatus(303);
    expect(WishReservation::query()->count())->toBe(0)->and(WishContribution::query()->count())->toBe(0)
        ->and($this->wish->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(DB::table('audit_logs')->count())->toBe($audit);
});

it('returns the viewer own shopping list grouped by owner, without other givers and excluded lists', function () {
    $reservation = ($this->reserve)('open');
    WishContribution::factory()->create(['reservation_id' => $reservation->id, 'user_id' => $this->grand->id, 'amount_minor' => 2550]);
    $received = Wish::factory()->received()->create(['owner_id' => $this->child->id, 'title' => 'Done']);
    WishReservation::factory()->create(['wish_id' => $received->id, 'user_id' => $this->grand->id]);
    // A list where the grandparent is recipient-side (own list) never appears even with stray rows.
    $own = Wish::factory()->create(['owner_id' => $this->grand->id, 'title' => 'Own']);
    WishReservation::factory()->create(['wish_id' => $own->id, 'user_id' => $this->grand->id]);

    $json = $this->actingAs($this->grand)->getJson('/wishlist/shopping')->assertOk()->json();
    expect($json['groups'])->toHaveCount(1)
        ->and($json['groups'][0]['owner'])->toBe(['id' => $this->child->id, 'name' => 'Adam'])
        ->and($json['groups'][0]['items'])->toBe([[
            'wish_id' => $this->wish->id, 'title' => 'Bike', 'price' => '200.00', 'currency' => 'EUR',
            'cobuy_state' => 'open', 'is_organizer' => false, 'my_amount' => '25.50',
        ]])
        ->and(json_encode($json))->not->toContain('Petra');

    $this->actingAs($this->child)->getJson('/wishlist/shopping')->assertOk()->assertExactJson(['groups' => []]);
});

it('never puts shopping into page props', function () {
    ($this->reserve)();

    $this->actingAs($this->parent)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p->missing('shopping')->missing('list.shopping'));
});

it('purges reservations of wishes received more than the retention days ago', function () {
    $old = Wish::factory()->create(['owner_id' => $this->child->id]);
    $old->forceFill(['received_at' => now()->subDays(31)])->save();
    $recent = Wish::factory()->create(['owner_id' => $this->child->id]);
    $recent->forceFill(['received_at' => now()->subDays(5)])->save();
    $open = ($this->reserve)();
    WishReservation::factory()->create(['wish_id' => $old->id, 'user_id' => $this->parent->id]);
    WishReservation::factory()->create(['wish_id' => $recent->id, 'user_id' => $this->parent->id]);

    $deleted = app(ReservationRetention::class)->purgeArchived(30);

    expect($deleted)->toBe(1)
        ->and(WishReservation::query()->where('wish_id', $old->id)->exists())->toBeFalse()
        ->and(WishReservation::query()->where('wish_id', $recent->id)->exists())->toBeTrue()
        ->and(WishReservation::query()->where('id', $open->id)->exists())->toBeTrue();
});
