<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('local');
    Queue::fake();
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
});

it('keeps every wishlist throttle in its own bucket (exhausting one route does not 429 another)', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertStatus(303);
    }
    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertStatus(429);

    // Other routes (different limits and prefixes) are unaffected by the exhausted fetch bucket.
    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", [])->assertStatus(302);
    $this->actingAs($this->parent)->getJson("/wishlist/namedays?calendar=sk&q=an")->assertOk();
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'Lamp'])->assertStatus(303);
});

it('refuses to fetch for a wish with an uploaded image (409) and keeps it ready', function () {
    $wish = Wish::factory()->create([
        'owner_id' => $this->parent->id, 'image_status' => 'ready', 'image_source' => 'uploaded',
        'image_path' => 'wish-images/1/a-full.jpg', 'thumb_path' => 'wish-images/1/a-thumb.jpg',
    ]);
    \App\Models\WishUrl::factory()->create(['wish_id' => $wish->id, 'url' => 'https://shop.example/a']);

    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertStatus(409);
    expect($wish->fresh()->image_status)->toBe('ready');
    Queue::assertNothingPushed();
});

it('resets pending in the job when an upload won the race, and keeps an older image visible on failure', function () {
    $uploaded = Wish::factory()->create(['image_status' => 'pending', 'image_source' => 'uploaded', 'image_path' => 'x/f.jpg', 'thumb_path' => 'x/t.jpg']);
    app()->call([new \App\Wishlist\Catalog\FetchWishImage($uploaded->id), 'handle']);
    expect($uploaded->fresh()->image_status)->toBe('ready');

    $fetched = Wish::factory()->create(['image_status' => 'pending', 'image_source' => 'fetched', 'image_path' => 'x/f.jpg', 'thumb_path' => 'x/t.jpg']);
    (new \App\Wishlist\Catalog\FetchWishImage($fetched->id))->failed(new RuntimeException('boom'));
    expect($fetched->fresh()->image_status)->toBe('ready');
});

it('keeps the old image and does not mark ready when the disk write fails', function () {
    $wish = Wish::factory()->create([
        'owner_id' => $this->parent->id, 'image_status' => 'ready', 'image_source' => 'fetched',
        'image_path' => 'wish-images/9/old-full.jpg', 'thumb_path' => 'wish-images/9/old-thumb.jpg',
    ]);
    Storage::disk('local')->put('wish-images/9/old-full.jpg', 'old');
    Storage::disk('local')->put('wish-images/9/old-thumb.jpg', 'old');
    $real = Storage::disk('local');
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('put')->andReturn(false);
    Storage::set('local', $failing);

    $store = app(\App\Wishlist\Catalog\WishImageStore::class);
    expect(fn () => $store->put($wish, new \App\Wishlist\Catalog\ProcessedImage('a', 'b', 1, 1), 'uploaded', null))
        ->toThrow(\App\Wishlist\Catalog\WishImageWriteFailed::class);

    $fresh = $wish->fresh();
    expect($fresh->image_source)->toBe('fetched')->and($fresh->image_path)->toBe('wish-images/9/old-full.jpg');
    expect($real->exists('wish-images/9/old-full.jpg'))->toBeTrue();
});

it('treats an empty nameday object as a no-op instead of a 500', function () {
    $existing = \App\Models\WishOccasion::factory()->nameday('sk', 'Ľubomír', 8, 13)->create(['owner_id' => $this->parent->id]);

    foreach ([[], (object) []] as $empty) {
        $this->actingAs($this->parent)->putJson('/wishlist/members/'.$this->parent->id.'/dates', ['nameday' => $empty])
            ->assertStatus(303);
    }
    expect(\App\Models\WishOccasion::query()->whereKey($existing->id)->exists())->toBeTrue();
});

it('applies list visibility to the image route with a uniform 404', function () {
    $owner = User::factory()->withPreset(Preset::Child)->create();
    $giver = User::factory()->withPreset(Preset::Parent)->create();
    $wish = Wish::factory()->create([
        'owner_id' => $owner->id, 'image_status' => 'ready', 'image_source' => 'uploaded',
        'image_path' => 'wish-images/5/a-full.jpg', 'thumb_path' => 'wish-images/5/a-thumb.jpg',
    ]);
    Storage::disk('local')->put('wish-images/5/a-thumb.jpg', 'jpg');
    $url = "/wishlist/wishes/{$wish->id}/image/thumb";

    $this->actingAs($giver)->get($url)->assertOk();
    $this->actingAs($owner)->get($url)->assertOk();

    // Received wishes: recipient-side only.
    $wish->forceFill(['received_at' => now()])->save();
    $this->actingAs($giver)->get($url)->assertNotFound();
    $this->actingAs($owner)->get($url)->assertOk();
    $wish->forceFill(['received_at' => null])->save();

    // Owner lost wishlist access: their list is gone for everyone else.
    \App\Models\ModulePermission::query()->where('user_id', $owner->id)->where('module', 'wishlist')->delete();
    $this->actingAs($giver)->get($url)->assertNotFound();

    // Unknown id looks the same as a hidden wish.
    $this->actingAs($giver)->get('/wishlist/wishes/999999/image/thumb')->assertNotFound();
});

it('re-checks the manager designation inside the giving transactions and locks the owner row on designation', function () {
    $owner = User::factory()->withPreset(Preset::Child)->create();
    $giver = User::factory()->withPreset(Preset::Parent)->create();
    $wish = Wish::factory()->create(['owner_id' => $owner->id]);
    $guard = app(\App\Wishlist\Giving\GivingGuard::class);

    \Illuminate\Support\Facades\DB::transaction(fn () => $guard->assertStillGiver($giver, $owner->id));

    // A designation committed after the stale pre-transaction check: the transaction now refuses with 403.
    \App\Models\WishlistManager::factory()->create(['owner_id' => $owner->id, 'manager_id' => $giver->id]);
    expect(fn () => \Illuminate\Support\Facades\DB::transaction(fn () => $guard->assertStillGiver($giver, $owner->id)))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->actingAs($giver)->post("/wishlist/wishes/{$wish->id}/reservation")->assertForbidden();
    expect(\App\Models\WishReservation::query()->count())->toBe(0);

    // Designation holds the exclusive side of the same lock.
    \App\Models\WishlistManager::query()->delete();
    $admin = User::factory()->admin()->create();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->actingAs($admin)->putJson("/settings/members/{$owner->id}/wishlist-managers", ['manager_ids' => [$giver->id]])->assertRedirect();
    $sql = collect(\Illuminate\Support\Facades\DB::getQueryLog())->pluck('query')->implode("\n");
    expect($sql)->toContain('from "users" where "users"."id" = ? limit 1 for update');
});
