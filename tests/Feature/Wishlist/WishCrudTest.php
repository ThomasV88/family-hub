<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use App\Models\WishUrl;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->withPreset(Preset::Child)->create();
    $this->grand = User::factory()->withPreset(Preset::Grandparent)->create();
});

it('creates a fully specified wish on the own list', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', [
        'title' => 'Lego set', 'notes' => 'The big one', 'price' => '12,50', 'currency' => 'EUR',
        'size_notes' => 'Blue', 'priority' => 'top',
        'urls' => [['url' => 'https://a.example/x', 'label' => 'Shop A'], ['url' => 'https://b.example/y']],
    ])->assertStatus(303)
        ->assertRedirect('/wishlist?member='.$this->parent->id)
        ->assertSessionHas('toast.key', 'wishlist.toast.saved');

    $wish = Wish::query()->sole();
    expect($wish->price_minor)->toBe(1250)
        ->and($wish->owner_id)->toBe($this->parent->id)
        ->and($wish->created_by_id)->toBe($this->parent->id)
        ->and($wish->priority->value)->toBe(2)
        ->and($wish->urls)->toHaveCount(2)
        ->and($wish->urls->pluck('url')->all())->toBe(['https://a.example/x', 'https://b.example/y'])
        ->and(session('flash_data.wishId'))->toBe($wish->id);
});

it('defaults currency to EUR and priority to love', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'Plain'])->assertStatus(303);

    $wish = Wish::query()->sole();
    expect($wish->price_currency)->toBe('EUR')->and($wish->priority->key())->toBe('love')->and($wish->price_minor)->toBeNull();
});

it('lets a designated manager add to the managed list and refuses other owners', function () {
    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $this->parent->id]);

    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'For kid', 'owner_id' => $this->child->id])
        ->assertStatus(303)->assertRedirect('/wishlist?member='.$this->child->id);
    expect(Wish::query()->sole())->owner_id->toBe($this->child->id)->created_by_id->toBe($this->parent->id);

    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'Nope', 'owner_id' => $this->grand->id])->assertForbidden();
    expect(Wish::count())->toBe(1);
});

it('updates fields and replaces urls in order, keeping urls when the key is absent', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id, 'title' => 'Old']);
    WishUrl::factory()->create(['wish_id' => $wish->id, 'url' => 'https://old.example', 'position' => 0]);

    $this->actingAs($this->parent)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'New', 'price' => '3'])
        ->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.saved');
    expect($wish->fresh()->title)->toBe('New')->and($wish->fresh()->price_minor)->toBe(300)
        ->and($wish->urls()->count())->toBe(1);

    $this->actingAs($this->parent)->patch("/wishlist/wishes/{$wish->id}", [
        'title' => 'New', 'price' => '', 'urls' => [['url' => 'https://z.example'], ['url' => 'https://y.example', 'label' => 'Y']],
    ])->assertStatus(303);
    expect($wish->fresh()->price_minor)->toBeNull()
        ->and($wish->urls()->pluck('url')->all())->toBe(['https://z.example', 'https://y.example']);
});

it('deletes a wish with its urls', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    WishUrl::factory()->create(['wish_id' => $wish->id]);

    $this->actingAs($this->parent)->delete("/wishlist/wishes/{$wish->id}")
        ->assertStatus(303)->assertRedirect('/wishlist?member='.$this->parent->id)
        ->assertSessionHas('toast.key', 'wishlist.toast.deleted');

    expect(Wish::count())->toBe(0)->and(WishUrl::count())->toBe(0);
});

it('marks a wish received and un-receives it', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);

    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/received")
        ->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.received');
    expect($wish->fresh()->received_at)->not->toBeNull();

    $this->actingAs($this->parent)->delete("/wishlist/wishes/{$wish->id}/received")->assertStatus(303);
    expect($wish->fresh()->received_at)->toBeNull();
});

it('refuses every write to a view-only member', function () {
    $viewer = User::factory()->withGrant(Module::Wishlist, AccessLevel::View)->create();
    $wish = Wish::factory()->create(['owner_id' => $viewer->id]);

    $this->actingAs($viewer)->post('/wishlist/wishes', ['title' => 'x'])->assertForbidden();
    $this->actingAs($viewer)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($viewer)->delete("/wishlist/wishes/{$wish->id}")->assertForbidden();
    $this->actingAs($viewer)->post("/wishlist/wishes/{$wish->id}/received")->assertForbidden();
    $this->actingAs($viewer)->delete("/wishlist/wishes/{$wish->id}/received")->assertForbidden();
    expect(Wish::count())->toBe(1)->and($wish->fresh()->title)->not->toBe('x');
});

it('refuses an unrelated edit-level member and an admin on someone elses wish', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id, 'title' => 'Mine']);
    $admin = User::factory()->admin()->withPreset(Preset::Parent)->create();

    foreach ([$this->child, $admin] as $actor) {
        $this->actingAs($actor)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'Hacked'])->assertForbidden();
        $this->actingAs($actor)->delete("/wishlist/wishes/{$wish->id}")->assertForbidden();
        $this->actingAs($actor)->post("/wishlist/wishes/{$wish->id}/received")->assertForbidden();
    }
    expect($wish->fresh()->title)->toBe('Mine')->and($wish->fresh()->received_at)->toBeNull();
});

it('ignores owner_id, image_status, received_at and created_by_id from the request', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', [
        'title' => 'Tamper', 'image_status' => 'ready', 'received_at' => now()->toIso8601String(), 'created_by_id' => $this->child->id,
    ])->assertStatus(303);
    $wish = Wish::query()->sole();
    expect($wish->image_status)->toBe('none')->and($wish->received_at)->toBeNull()->and($wish->created_by_id)->toBe($this->parent->id);

    $this->actingAs($this->parent)->patch("/wishlist/wishes/{$wish->id}", [
        'title' => 'Tamper 2', 'owner_id' => $this->child->id, 'image_status' => 'ready',
        'received_at' => now()->toIso8601String(), 'created_by_id' => $this->child->id,
    ])->assertStatus(303);
    $wish->refresh();
    expect($wish->owner_id)->toBe($this->parent->id)->and($wish->image_status)->toBe('none')
        ->and($wish->received_at)->toBeNull()->and($wish->created_by_id)->toBe($this->parent->id);
});

it('validates with i18n keys', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => '  '])
        ->assertSessionHasErrors(['title' => 'wishlist.error.title_required']);
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'x', 'price' => '12.345'])
        ->assertSessionHasErrors(['price' => 'wishlist.error.price_invalid']);
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'x', 'currency' => 'XXX'])->assertSessionHasErrors('currency');
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'x', 'priority' => 'urgent'])->assertSessionHasErrors('priority');
    expect(Wish::count())->toBe(0);
});
