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
