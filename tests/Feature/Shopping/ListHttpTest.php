<?php

use App\Access\AccessLevel;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Shopping\ItemState;
use App\Shopping\ListStatus;
use App\Shopping\MemberRole;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\ShoppingFixtures as F;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(fn () => Carbon::setTestNow());

it('creates a list and redirects to it with the picker flag', function () {
    $me = F::user();
    $store = ShoppingStore::factory()->create();

    $response = $this->actingAs($me)->post('/shopping/lists', ['name' => '  Weekend  ', 'store_id' => $store->id]);

    $list = ShoppingList::query()->firstOrFail();
    $response->assertStatus(303)->assertRedirect("/shopping/lists/{$list->id}")
        ->assertSessionHas('flash_data', ['open_picker' => true])
        ->assertSessionHas('toast.key', 'shopping.toast.list_created');
    expect($list->name)->toBe('Weekend')->and($list->creator_id)->toBe($me->id)->and($list->responsible_id)->toBe($me->id);
});

it('refuses an empty name and an unknown store', function () {
    $me = F::user();

    $this->actingAs($me)->post('/shopping/lists', ['name' => ' '])->assertSessionHasErrors(['name' => 'shopping.error.name_invalid']);
    $this->actingAs($me)->post('/shopping/lists', ['name' => 'X', 'store_id' => 999999])->assertSessionHasErrors('store_id');
    expect(ShoppingList::query()->count())->toBe(0);
});

it('updates the settings of an open list and answers 303 back', function () {
    $me = F::user();
    $list = F::list($me);

    $this->actingAs($me)->from('/shopping/lists/'.$list->id)->put("/shopping/lists/{$list->id}", ['name' => 'Renamed'])
        ->assertStatus(303)->assertRedirect('/shopping/lists/'.$list->id)->assertSessionHas('toast.key', 'shopping.toast.list_saved');
    expect($list->fresh()->name)->toBe('Renamed');
});

it('syncs people and keeps untouched keys out of the update', function () {
    $me = F::user();
    $mate = F::user();
    $list = F::list($me);
    $list->forceFill(['name' => 'Keep'])->save();

    $this->actingAs($me)->put("/shopping/lists/{$list->id}", ['contributor_ids' => [$mate->id]])->assertStatus(303);

    expect($list->fresh()->name)->toBe('Keep')
        ->and($list->members()->where('user_id', $mate->id)->value('role'))->toBe(MemberRole::Contributor);
});

it('stores a family-timezone reminder as UTC and removes it with null', function () {
    Carbon::setTestNow('2027-05-01 10:00:00 UTC');
    $me = F::user();
    $list = F::list($me);

    $this->actingAs($me)->put("/shopping/lists/{$list->id}", ['remind_at' => '2027-05-10T18:30'])->assertStatus(303);
    // Europe/Bratislava is UTC+2 in May.
    expect($list->fresh()->remind_at->utc()->format('Y-m-d H:i'))->toBe('2027-05-10 16:30');

    $this->actingAs($me)->put("/shopping/lists/{$list->id}", ['remind_at' => null])->assertStatus(303);
    expect($list->fresh()->remind_at)->toBeNull();
});

it('refuses a reminder in the past and a malformed one', function () {
    Carbon::setTestNow('2027-05-01 10:00:00 UTC');
    $me = F::user();
    $list = F::list($me);

    $this->actingAs($me)->put("/shopping/lists/{$list->id}", ['remind_at' => '2027-05-01T11:00'])
        ->assertSessionHasErrors(['remind_at' => 'shopping.error.reminder_past']);
    $this->actingAs($me)->put("/shopping/lists/{$list->id}", ['remind_at' => '2027-05-10 18:30'])->assertSessionHasErrors('remind_at');
    $this->actingAs($me)->put("/shopping/lists/{$list->id}", ['remind_at' => '2027-05-10T18:30:00Z'])->assertSessionHasErrors('remind_at');
    expect($list->fresh()->remind_at)->toBeNull();
});

it('converts a reminder on a nonexistent spring-forward local time to a real instant', function () {
    Carbon::setTestNow('2027-03-01 10:00:00 UTC');
    $me = F::user();
    $list = F::list($me);

    // 2027-03-28 02:30 does not exist in Europe/Bratislava; PHP moves it to 03:30 CEST (01:30 UTC).
    $this->actingAs($me)->put("/shopping/lists/{$list->id}", ['remind_at' => '2027-03-28T02:30'])->assertStatus(303);

    expect($list->fresh()->remind_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-28 01:30');
});

it('deletes an open list and goes to the overview', function () {
    $me = F::user();
    $list = F::list($me);

    $this->actingAs($me)->delete("/shopping/lists/{$list->id}")->assertStatus(303)->assertRedirect('/shopping');
    expect(ShoppingList::query()->count())->toBe(0);
});

it('locks and unlocks with an absolute target', function () {
    $me = F::user();
    $list = F::list($me);
    F::item($list);

    $this->actingAs($me)->put("/shopping/lists/{$list->id}/lock", ['locked' => true])->assertStatus(303)
        ->assertSessionHas('toast.key', 'shopping.toast.locked');
    expect($list->fresh()->status)->toBe(ListStatus::Locked);

    $this->actingAs($me)->put("/shopping/lists/{$list->id}/lock", ['locked' => false])->assertStatus(303);
    expect($list->fresh()->status)->toBe(ListStatus::Open);
    $this->actingAs($me)->put("/shopping/lists/{$list->id}/lock", [])->assertSessionHasErrors('locked');
});

it('refuses to lock an empty list with the service error key', function () {
    $me = F::user();
    $list = F::list($me);

    $this->actingAs($me)->put("/shopping/lists/{$list->id}/lock", ['locked' => true])->assertSessionHasErrors(['list' => 'shopping.error.lock_empty']);
});

it('finishes without a carry list and answers back', function () {
    $me = F::user();
    $list = F::list($me, status: ListStatus::Locked);
    F::item($list, ['state' => ItemState::Bought]);

    $this->actingAs($me)->from("/shopping/lists/{$list->id}")->post("/shopping/lists/{$list->id}/finish", [])
        ->assertStatus(303)->assertRedirect("/shopping/lists/{$list->id}")->assertSessionHas('toast.key', 'shopping.toast.finished');
    expect($list->fresh()->status)->toBe(ListStatus::Done);
});

it('finishes with a carry list and redirects to it', function () {
    $me = F::user();
    $list = F::list($me, status: ListStatus::Locked);
    F::item($list, ['state' => ItemState::Unavailable, 'name' => 'Basil']);
    F::item($list, ['state' => ItemState::Bought]);

    $response = $this->actingAs($me)->post("/shopping/lists/{$list->id}/finish", ['carry_name' => 'Missing']);

    $carry = ShoppingList::query()->where('source_list_id', $list->id)->firstOrFail();
    $response->assertStatus(303)->assertRedirect("/shopping/lists/{$carry->id}")->assertSessionHas('toast.key', 'shopping.toast.finished_carry');
    expect($carry->name)->toBe('Missing')->and(ShoppingItem::query()->where('list_id', $carry->id)->pluck('name')->all())->toBe(['Basil']);
});

it('refuses to finish while an item is pending', function () {
    $me = F::user();
    $list = F::list($me, status: ListStatus::Locked);
    F::item($list);

    $this->actingAs($me)->post("/shopping/lists/{$list->id}/finish", [])->assertStatus(409);
});

it('copies a list with and without keeping the people', function () {
    $me = F::user();
    $mate = F::user();
    $list = F::list($me, contributors: [$mate]);
    F::item($list, ['name' => 'Eggs']);

    $this->actingAs($me)->post("/shopping/lists/{$list->id}/copy", ['name' => 'Again', 'keep' => false])->assertStatus(303);
    $plain = ShoppingList::query()->where('name', 'Again')->firstOrFail();
    expect($plain->members()->count())->toBe(0)->and($plain->items()->pluck('name')->all())->toBe(['Eggs']);

    $this->actingAs($me)->post("/shopping/lists/{$list->id}/copy", ['name' => 'Again2', 'keep' => true])
        ->assertRedirect('/shopping/lists/'.ShoppingList::query()->where('name', 'Again2')->value('id'));
    expect(ShoppingList::query()->where('name', 'Again2')->firstOrFail()->members()->where('user_id', $mate->id)->exists())->toBeTrue();
});

it('validates the copy name and keep flag', function () {
    $me = F::user();
    $list = F::list($me);

    $this->actingAs($me)->post("/shopping/lists/{$list->id}/copy", ['name' => '', 'keep' => true])->assertSessionHasErrors('name');
    $this->actingAs($me)->post("/shopping/lists/{$list->id}/copy", ['name' => 'X'])->assertSessionHasErrors('keep');
    $this->actingAs($me)->post("/shopping/lists/{$list->id}/copy", ['name' => str_repeat('a', 81), 'keep' => true])->assertSessionHasErrors('name');
});

it('lets a view-only member copy nothing', function () {
    $owner = F::user();
    $viewer = F::user(AccessLevel::View);
    $list = F::list($owner, viewers: [$viewer]);

    $this->actingAs($viewer)->post("/shopping/lists/{$list->id}/copy", ['name' => 'X', 'keep' => false])->assertForbidden();
});
