<?php

use App\Access\AccessLevel;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Shopping\ItemState;
use App\Shopping\ListStatus;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ShoppingFixtures as F;

afterEach(fn () => Carbon::setTestNow());

it('lists only the visible lists with counts, ordered by reminder then newest', function () {
    Carbon::setTestNow('2027-05-01 10:00:00 UTC');
    $me = F::user();
    $other = F::user();
    $store = ShoppingStore::factory()->create(['name' => 'Tesco']);

    $none = F::list($me);
    $none->forceFill(['created_at' => now()->subDay()])->save();
    $soon = F::list($other, $me);
    $soon->forceFill(['remind_at' => now()->addHour(), 'store_id' => $store->id])->save();
    $later = F::list($me);
    $later->forceFill(['remind_at' => now()->addDay()])->save();
    $newestNone = F::list($other, $other, contributors: [$me]);
    $hidden = F::list($other);
    F::item($soon, ['state' => ItemState::Bought]);
    F::item($soon, ['state' => ItemState::Unavailable]);
    F::item($soon);

    $this->actingAs($me)->get('/shopping')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Shopping/Index')
        ->where('lists.0.id', $soon->id)
        ->where('lists.0.itemsCount', 3)
        ->where('lists.0.boughtCount', 1)
        ->where('lists.0.unavailableCount', 1)
        ->where('lists.0.store.name', 'Tesco')
        ->where('lists.0.reminderPassed', false)
        ->where('lists.1.id', $later->id)
        ->where('lists.2.id', $newestNone->id)
        ->where('lists.3.id', $none->id)
        ->has('lists', 4)
        ->where('counts.open', 4)
        ->where('canCreate', true));

    expect($hidden->exists)->toBeTrue();
});

it('paginates the archive of done lists, newest first', function () {
    $me = F::user();
    foreach (range(1, 23) as $n) {
        F::list($me, status: ListStatus::Done)->forceFill(['done_at' => now()->subMinutes(30 - $n)])->save();
    }

    $this->actingAs($me)->get('/shopping?tab=archive')->assertInertia(fn (Assert $p) => $p
        ->component('Shopping/Index')
        ->where('tab', 'archive')
        ->has('archive.data', 20)
        ->where('archive.total', 23)
        ->where('archive.lastPage', 2)
        ->where('counts.archive', 23)
        ->where('counts.open', 0));
    $this->actingAs($me)->get('/shopping?tab=archive&page=2')->assertInertia(fn (Assert $p) => $p->has('archive.data', 3));
});

it('does not let a guest preset create but shows lists where they are viewer', function () {
    $owner = F::user();
    $viewer = F::user(AccessLevel::View);
    $list = F::list($owner, viewers: [$viewer]);

    $this->actingAs($viewer)->get('/shopping')->assertInertia(fn (Assert $p) => $p
        ->where('canCreate', false)
        ->where('lists.0.id', $list->id)
        ->where('lists.0.abilities.copy', false));
});

it('builds the detail props for the responsible person', function () {
    $creator = F::user();
    $resp = F::user();
    $viewer = F::user(AccessLevel::View);
    $kid = F::user(child: true);
    $list = F::list($creator, $resp, contributors: [$kid], viewers: [$viewer]);
    $unit = ShoppingUnit::factory()->create();
    $catalog = ShoppingCatalogItem::factory()->create(['name' => 'Milk', 'default_unit_id' => $unit->id]);
    F::item($list, ['name' => 'Milk', 'catalog_item_id' => $catalog->id, 'quantity' => '0.50', 'unit_id' => $unit->id]);
    NotificationPreference::query()->create(['user_id' => $resp->id, 'channel' => 'push', 'enabled' => true]);
    PushSubscription::query()->create(['user_id' => $resp->id, 'endpoint' => 'https://push.example/x', 'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/x'), 'p256dh' => 'a', 'auth' => 'b']);

    $this->actingAs($resp)->get("/shopping/lists/{$list->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Shopping/Show')
        ->where('list.id', $list->id)
        ->where('list.responsible.id', $resp->id)
        ->where('list.creator.id', $creator->id)
        ->where('list.responsibleFallback', null)
        ->where('items.0.quantity', '0.50')
        ->where('items.0.unitId', $unit->id)
        ->where('items.0.imageVersion', null)
        ->where('abilities.lock', true)
        ->where('abilities.setReminder', true)
        ->where('members.contributors.0.isChild', true)
        ->where('members.viewers.0.id', $viewer->id)
        ->has('candidates.responsible')
        ->has('candidates.contributors')
        ->where('responsiblePushEnabled', true)
        ->where('catalog.0.name', 'Milk')
        ->has('units')
        ->has('stores'));
});

it('hides candidates, catalog and the push flag from a viewer and never exposes e-mail addresses', function () {
    $creator = F::user();
    $viewer = F::user(AccessLevel::View);
    $list = F::list($creator, viewers: [$viewer]);

    $response = $this->actingAs($viewer)->get("/shopping/lists/{$list->id}")->assertOk();
    $response->assertInertia(fn (Assert $p) => $p
        ->missing('candidates')->missing('catalog')->missing('responsiblePushEnabled')
        ->where('abilities.editItems', false));

    expect($response->getContent())->not->toContain($creator->email);
});

it('shows the fallback notice only to the creator', function () {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp);
    F::setLevel($resp, AccessLevel::View);
    $resp->forceFill(['name' => 'Gone Person'])->save();

    $this->actingAs($creator)->get("/shopping/lists/{$list->id}")->assertInertia(fn (Assert $p) => $p
        ->where('list.responsible.id', $creator->id)
        ->where('list.responsibleFallback.previousName', 'Gone Person'));

    $this->actingAs($resp)->get("/shopping/lists/{$list->id}")->assertInertia(fn (Assert $p) => $p
        ->where('list.responsibleFallback', null));
});

it('keeps the list id route numeric and answers 404 for a list nobody can see', function () {
    $me = F::user();
    $list = ShoppingList::factory()->create();

    $this->actingAs($me)->get("/shopping/lists/{$list->id}")->assertNotFound();
    $this->actingAs($me)->get('/shopping/lists/999999')->assertNotFound();
    $this->actingAs($me)->get('/shopping/lists/abc')->assertNotFound();
});
