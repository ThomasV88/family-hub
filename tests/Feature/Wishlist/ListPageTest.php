<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Petra']);
    $this->child = User::factory()->withPreset(Preset::Child)->create(['name' => 'Adam']);
    $this->grand = User::factory()->withPreset(Preset::Grandparent)->create(['name' => 'Babka']);
});

it('opens the own list by default with the viewer first and a received key', function () {
    $this->actingAs($this->parent)->get('/wishlist')->assertInertia(fn (Assert $p) => $p
        ->component('Wishlist/Index', false)
        ->where('members.0.id', $this->parent->id)->where('members.0.is_viewer', true)
        ->where('members.1.name', 'Adam')->where('members.2.name', 'Babka')
        ->where('list.owner.id', $this->parent->id)
        ->where('list.viewer_is_recipient', true)->where('list.viewer_is_manager', false)->where('list.can_edit', true)
        ->has('list.received')
        ->where('selected', null));
});

it('keeps guest-preset members out of the members list', function () {
    $guest = User::factory()->withPreset(Preset::Guest)->create();

    $this->actingAs($this->parent)->get('/wishlist')->assertInertia(fn (Assert $p) => $p
        ->has('members', 3)->where('members', fn ($m) => ! collect($m)->contains('id', $guest->id)));
    $this->actingAs($this->parent)->get('/wishlist?member='.$guest->id)->assertForbidden();
});

it('treats a manager as the recipient and everyone else as a giver', function () {
    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $this->parent->id]);
    Wish::factory()->create(['owner_id' => $this->child->id, 'title' => 'Open']);
    Wish::factory()->received()->create(['owner_id' => $this->child->id, 'title' => 'Got it']);

    $this->actingAs($this->parent)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.viewer_is_recipient', true)->where('list.viewer_is_manager', true)->where('list.can_edit', true)
        ->has('list.wishes', 1)->has('list.received', 1));

    $this->actingAs($this->grand)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.viewer_is_recipient', false)->where('list.viewer_is_manager', false)->where('list.can_edit', false)
        ->has('list.wishes', 1)->missing('list.received'));
});

it('never makes an admin recipient without a manager row', function () {
    $admin = User::factory()->admin()->withPreset(Preset::Parent)->create();

    $this->actingAs($admin)->get('/wishlist?member='.$this->child->id)->assertInertia(fn (Assert $p) => $p
        ->where('list.viewer_is_recipient', false)->where('list.can_edit', false)->missing('list.received'));
});

it('answers every probing case with the same 403', function () {
    $guest = User::factory()->withPreset(Preset::Guest)->create();
    $foreign = Wish::factory()->create(['owner_id' => $this->child->id]);
    $receivedForeign = Wish::factory()->received()->create(['owner_id' => $this->child->id]);

    $cases = [
        '/wishlist?member='.$guest->id,
        '/wishlist?member=999999',
        '/wishlist?member=abc',
        '/wishlist?wish='.$foreign->id,
        '/wishlist?wish=999999',
        '/wishlist?wish=abc',
        '/wishlist?member='.$this->child->id.'&wish=new',
        '/wishlist?member='.$this->child->id.'&wish='.$receivedForeign->id,
    ];
    $bodies = [];
    foreach ($cases as $url) {
        $response = $this->actingAs($this->grand)->get($url);
        $response->assertForbidden();
        // The error page only echoes the request URL back; everything else must be byte-identical.
        $bodies[] = preg_replace('/"url":"[^"]*"/', '"url":""', $response->getContent());
    }
    expect(array_unique($bodies))->toHaveCount(1);
});

it('accepts wish=new on an editable list and a wish on the opened list', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);

    $this->actingAs($this->parent)->get('/wishlist?wish=new')->assertInertia(fn (Assert $p) => $p->where('selected', 'new'));
    $this->actingAs($this->parent)->get('/wishlist?wish='.$wish->id)->assertInertia(fn (Assert $p) => $p->where('selected', $wish->id));
});

it('ignores unknown query parameters such as available', function () {
    Wish::factory()->create(['owner_id' => $this->child->id]);
    $plain = $this->actingAs($this->grand)->get('/wishlist?member='.$this->child->id);
    $extra = $this->actingAs($this->grand)->get('/wishlist?member='.$this->child->id.'&available=1');

    expect($extra->viewData('page')['props'])->toEqual($plain->viewData('page')['props']);
});

it('exposes exactly the contract keys per wish', function () {
    Wish::factory()->create(['owner_id' => $this->parent->id, 'price_minor' => 1250]);

    $this->actingAs($this->parent)->get('/wishlist')->assertInertia(fn (Assert $p) => $p
        ->has('list.wishes.0', fn (Assert $w) => $w
            ->where('price', '12.50')->where('currency', 'EUR')->where('priority', 'love')->where('received', false)
            ->has('image', fn (Assert $i) => $i->where('status', 'none')->where('thumb_url', null)->where('full_url', null)->where('source', null)->where('host', null))
            ->hasAll(['id', 'title', 'notes', 'size_notes', 'urls'])));

    $wish = $this->actingAs($this->parent)->get('/wishlist')->viewData('page')['props']['list']['wishes'][0];
    expect(array_keys($wish))->toBe(['id', 'title', 'notes', 'price', 'currency', 'size_notes', 'priority', 'urls', 'image', 'received']);
});

it('orders by priority then newest', function () {
    Wish::factory()->create(['owner_id' => $this->parent->id, 'title' => 'nice-new', 'priority' => 0]);
    Wish::factory()->create(['owner_id' => $this->parent->id, 'title' => 'top-old', 'priority' => 2, 'created_at' => now()->subDay()]);
    Wish::factory()->create(['owner_id' => $this->parent->id, 'title' => 'love-old', 'priority' => 1, 'created_at' => now()->subDays(2)]);
    Wish::factory()->create(['owner_id' => $this->parent->id, 'title' => 'love-new', 'priority' => 1]);

    $titles = array_column($this->actingAs($this->parent)->get('/wishlist')->viewData('page')['props']['list']['wishes'], 'title');

    expect($titles)->toBe(['top-old', 'love-new', 'love-old', 'nice-new']);
});
