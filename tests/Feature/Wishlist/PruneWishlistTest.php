<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use App\Models\WishReservation;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

afterEach(fn () => Carbon::setTestNow());

it('deletes reservations of wishes received more than 30 days ago and keeps newer ones', function () {
    Carbon::setTestNow('2027-05-10 10:00:00 UTC');
    $giver = User::factory()->withPreset(Preset::Parent)->create();
    $old = Wish::factory()->create(['received_at' => now()->subDays(31)]);
    $recent = Wish::factory()->create(['received_at' => now()->subDays(29)]);
    $open = Wish::factory()->create();
    foreach ([$old, $recent, $open] as $wish) {
        WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $giver->id]);
    }

    $this->artisan('wishlist:prune')->assertSuccessful();

    expect(WishReservation::query()->pluck('wish_id')->sort()->values()->all())->toBe([$recent->id, $open->id]);
});

it('purges reservations a manager holds on the list they manage', function () {
    $owner = User::factory()->withPreset(Preset::Child)->create();
    $manager = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $wish = Wish::factory()->create(['owner_id' => $owner->id]);
    $mine = Wish::factory()->create(['owner_id' => $owner->id]);
    WishReservation::factory()->create(['wish_id' => $mine->id, 'user_id' => $manager->id]);
    WishReservation::factory()->create(['wish_id' => $wish->id, 'user_id' => $other->id]);
    WishlistManager::factory()->create(['owner_id' => $owner->id, 'manager_id' => $manager->id]);

    $this->artisan('wishlist:prune')->assertSuccessful();

    expect(WishReservation::query()->count())->toBe(1)
        ->and(WishReservation::query()->sole()->user_id)->toBe($other->id);
});

it('sweeps only unreferenced wish image files older than 60 minutes', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $wish = Wish::factory()->create(['image_path' => 'wish-images/1/keep-full.jpg', 'thumb_path' => 'wish-images/1/keep-thumb.jpg']);
    foreach (['keep-full.jpg', 'keep-thumb.jpg', 'orphan-old.jpg', 'orphan-new.jpg'] as $name) {
        $disk->put("wish-images/1/$name", 'x');
    }
    foreach (['keep-full.jpg', 'keep-thumb.jpg', 'orphan-old.jpg'] as $name) {
        touch($disk->path("wish-images/1/$name"), time() - 7200);
    }

    $this->artisan('wishlist:prune')->assertSuccessful();

    $disk->assertMissing('wish-images/1/orphan-old.jpg');
    $disk->assertExists(['wish-images/1/orphan-new.jpg', $wish->image_path, $wish->thumb_path]);
});

it('is scheduled', function () {
    $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);

    expect($events['wishlist-prune']->expression)->toBe('0 0 * * *')
        ->and($events['occasions-enqueue']->expression)->toBe('0 * * * *')
        ->and($events['occasions-enqueue']->withoutOverlapping)->toBeTrue();
});
