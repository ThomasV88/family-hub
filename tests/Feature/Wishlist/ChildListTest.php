<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->admin = User::factory()->admin()->create();
    $this->parent = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Petra']);
    $this->unrelated = User::factory()->withPreset(Preset::Parent)->create();
    $this->viewer = User::factory()->withPreset(Preset::Grandparent)->create();
    $this->child = User::factory()->child()->withPreset(Preset::Child)->create(['name' => 'Ema']);
    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $this->parent->id]);
});

it('lists the child as a wishlist owner for every member who sees Wishlist', function () {
    foreach ([$this->parent, $this->unrelated, $this->viewer] as $member) {
        $this->actingAs($member)->get('/wishlist')->assertInertia(fn (Assert $p) => $p
            ->where('members', fn ($m) => collect($m)->contains('id', $this->child->id)));
    }
});

it('lets the chosen parent add, edit and delete wishes on the child list', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'Lego', 'owner_id' => $this->child->id])
        ->assertStatus(303)->assertRedirect('/wishlist?member='.$this->child->id);
    $wish = Wish::query()->sole();
    expect($wish->owner_id)->toBe($this->child->id)->and($wish->created_by_id)->toBe($this->parent->id);

    $this->actingAs($this->parent)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'Duplo'])->assertStatus(303);
    expect($wish->fresh()->title)->toBe('Duplo');

    $this->actingAs($this->parent)->delete("/wishlist/wishes/{$wish->id}")->assertStatus(303);
    expect(Wish::count())->toBe(0);
});

it('refuses an unrelated member and an admin who is not a manager', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->child->id, 'title' => 'Kid wish']);
    $adminWithEdit = User::factory()->admin()->withPreset(Preset::Parent)->create();

    foreach ([$this->unrelated, $adminWithEdit, $this->admin] as $actor) {
        $this->actingAs($actor)->post('/wishlist/wishes', ['title' => 'Nope', 'owner_id' => $this->child->id])->assertForbidden();
        $this->actingAs($actor)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'Hacked'])->assertForbidden();
        $this->actingAs($actor)->delete("/wishlist/wishes/{$wish->id}")->assertForbidden();
    }
    expect(Wish::count())->toBe(1)->and($wish->fresh()->title)->toBe('Kid wish');
});
