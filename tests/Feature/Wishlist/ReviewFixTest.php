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
