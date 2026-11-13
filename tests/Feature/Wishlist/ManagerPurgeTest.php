<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishlistManager;
use App\Models\WishReservation;
use App\Wishlist\Giving\ManagerReservationPurger;
use App\Wishlist\Giving\PurgeManagerReservations;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->admin = User::factory()->admin()->create();
    $this->owner = User::factory()->withPreset(Preset::Child)->create();
    $this->other = User::factory()->withPreset(Preset::Child)->create();
    $this->giver = User::factory()->withPreset(Preset::Parent)->create();
    $this->third = User::factory()->withPreset(Preset::Grandparent)->create();

    $this->w1 = Wish::factory()->create(['owner_id' => $this->owner->id]);
    $this->w2 = Wish::factory()->create(['owner_id' => $this->owner->id]);
    $this->w3 = Wish::factory()->create(['owner_id' => $this->other->id]);

    // Giver organizes w1 (with a joiner), joins third's reservation on w2, and organizes on another owner's list.
    $r1 = WishReservation::factory()->cobuyOpen()->create(['wish_id' => $this->w1->id, 'user_id' => $this->giver->id]);
    WishContribution::factory()->create(['reservation_id' => $r1->id, 'user_id' => $this->third->id, 'amount_minor' => 300]);
    $r2 = WishReservation::factory()->cobuyOpen()->create(['wish_id' => $this->w2->id, 'user_id' => $this->third->id]);
    WishContribution::factory()->create(['reservation_id' => $r2->id, 'user_id' => $this->giver->id, 'amount_minor' => 500]);
    WishReservation::factory()->create(['wish_id' => $this->w3->id, 'user_id' => $this->giver->id]);
});

function putManagers($test, array $ids)
{
    return $test->actingAs($test->admin)->putJson('/settings/members/'.$test->owner->id.'/wishlist-managers', ['manager_ids' => $ids]);
}

it('purges the new manager reservations and contributions on that owner list only', function () {
    app(ManagerReservationPurger::class)->purge($this->owner->id, $this->giver->id);

    expect(WishReservation::query()->where('wish_id', $this->w1->id)->exists())->toBeFalse()
        ->and(WishContribution::query()->where('user_id', $this->third->id)->where('amount_minor', 300)->exists())->toBeFalse()
        ->and(WishContribution::query()->where('user_id', $this->giver->id)->where('amount_minor', 500)->exists())->toBeFalse()
        ->and(WishReservation::query()->where('wish_id', $this->w2->id)->exists())->toBeTrue()
        ->and(WishReservation::query()->where('wish_id', $this->w3->id)->where('user_id', $this->giver->id)->exists())->toBeTrue();
});

it('dispatches the job only for newly added managers, and the purge then makes the view recipient-shaped', function () {
    Queue::fake();
    putManagers($this, [$this->giver->id])->assertRedirect();
    Queue::assertPushed(PurgeManagerReservations::class, fn ($job) => $job->ownerId === $this->owner->id && $job->managerId === $this->giver->id);

    Queue::fake();
    putManagers($this, [$this->giver->id])->assertRedirect();
    Queue::assertNotPushed(PurgeManagerReservations::class);
});

it('runs the purge through the queue and leaves the manager an OwnListView-shaped page', function () {
    // The test queue is synchronous: designating the manager purges immediately.
    putManagers($this, [$this->giver->id])->assertRedirect();

    expect(WishReservation::query()->where('wish_id', $this->w1->id)->exists())->toBeFalse()
        ->and(WishReservation::query()->where('wish_id', $this->w3->id)->exists())->toBeTrue();

    $this->actingAs($this->giver)->get('/wishlist?member='.$this->owner->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.viewer_is_recipient', true)->missing('list.viewer_can_reserve')->missing('list.wishes.0.reservation'));
});

it('sweeps every manager pair with purgeAll', function () {
    WishlistManager::factory()->create(['owner_id' => $this->owner->id, 'manager_id' => $this->giver->id]);

    app(ManagerReservationPurger::class)->purgeAll();

    expect(WishReservation::query()->where('wish_id', $this->w1->id)->exists())->toBeFalse();
});
