<?php

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingUnit;
use App\Shopping\ItemState;
use App\Shopping\ListStatus;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\ShoppingFixtures as F;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('adds several items at once from the catalog and free text', function () {
    $me = F::user();
    $list = F::list($me);
    $unit = ShoppingUnit::factory()->create();
    $catalog = ShoppingCatalogItem::factory()->create(['name' => 'Milk', 'default_unit_id' => $unit->id]);

    $this->actingAs($me)->post("/shopping/lists/{$list->id}/items", ['items' => [
        ['catalog_item_id' => $catalog->id, 'quantity' => '2'],
        ['name' => 'Soap', 'quantity' => '1,5', 'note' => 'blue', 'save_to_catalog' => true],
    ]])->assertStatus(303)->assertSessionHas('toast.key', 'shopping.toast.items_added')->assertSessionHas('toast.count', 2);

    $items = ShoppingItem::query()->where('list_id', $list->id)->orderBy('position')->get();
    expect($items)->toHaveCount(2)
        ->and($items[0]->name)->toBe('Milk')->and($items[0]->unit_id)->toBe($unit->id)
        ->and((string) $items[1]->quantity)->toBe('1.50')->and($items[1]->note)->toBe('blue')
        ->and(ShoppingCatalogItem::query()->where('name', 'Soap')->exists())->toBeTrue();
});

it('merges an already pending catalog item and reports it', function () {
    $me = F::user();
    $list = F::list($me);
    $catalog = ShoppingCatalogItem::factory()->create(['name' => 'Milk']);
    $payload = ['items' => [['catalog_item_id' => $catalog->id, 'quantity' => 1]]];

    $this->actingAs($me)->post("/shopping/lists/{$list->id}/items", $payload);
    $this->actingAs($me)->post("/shopping/lists/{$list->id}/items", $payload)->assertSessionHas('toast.merged', ['Milk'])->assertSessionHas('toast.key', 'shopping.toast.quantity_updated')->assertSessionHas('toast.params.name', 'Milk');

    expect(ShoppingItem::query()->where('list_id', $list->id)->count())->toBe(1)
        ->and((string) ShoppingItem::query()->where('list_id', $list->id)->value('quantity'))->toBe('2.00');
});

it('refuses zero, more than 50 and malformed items', function () {
    $me = F::user();
    $list = F::list($me);
    $url = "/shopping/lists/{$list->id}/items";

    $this->actingAs($me)->post($url, ['items' => []])->assertSessionHasErrors(['items' => 'shopping.error.items_count']);
    $this->actingAs($me)->post($url, [])->assertSessionHasErrors('items');
    $this->actingAs($me)->post($url, ['items' => array_fill(0, 51, ['name' => 'x'])])->assertSessionHasErrors('items');
    $this->actingAs($me)->post($url, ['items' => [['quantity' => '1']]])->assertSessionHasErrors(['items.0.name' => 'shopping.error.name_invalid']);
    $this->actingAs($me)->post($url, ['items' => [['name' => 'x', 'note' => str_repeat('n', 201)]]])->assertSessionHasErrors('items.0.note');
    expect(ShoppingItem::query()->count())->toBe(0);
});

it('updates and removes an item', function () {
    $me = F::user();
    $list = F::list($me);
    $item = F::item($list, ['name' => 'Old']);

    $this->actingAs($me)->put("/shopping/lists/{$list->id}/items/{$item->id}", ['name' => 'New', 'quantity' => '3,25', 'note' => ''])
        ->assertStatus(303)->assertSessionHas('toast.key', 'shopping.toast.item_saved');
    $item->refresh();
    expect($item->name)->toBe('New')->and((string) $item->quantity)->toBe('3.25')->and($item->note)->toBeNull();

    $this->actingAs($me)->delete("/shopping/lists/{$list->id}/items/{$item->id}")->assertStatus(303);
    expect(ShoppingItem::query()->count())->toBe(0);
});

it('refuses item edits while the list is locked', function () {
    $me = F::user();
    $list = F::list($me, status: ListStatus::Locked);
    $item = F::item($list);

    $this->actingAs($me)->put("/shopping/lists/{$list->id}/items/{$item->id}", ['name' => 'X'])->assertStatus(409);
    $this->actingAs($me)->delete("/shopping/lists/{$list->id}/items/{$item->id}")->assertStatus(409);
    $this->actingAs($me)->post("/shopping/lists/{$list->id}/items", ['items' => [['name' => 'x']]])->assertStatus(409);
});

it('marks items bought, unavailable and pending again with an enum', function () {
    $me = F::user();
    $list = F::list($me, status: ListStatus::Locked);
    $item = F::item($list);
    $url = "/shopping/lists/{$list->id}/items/{$item->id}/state";

    $this->actingAs($me)->put($url, ['state' => 'bought'])->assertStatus(303);
    expect($item->fresh()->state)->toBe(ItemState::Bought);
    $this->actingAs($me)->put($url, ['state' => 'unavailable'])->assertStatus(303);
    expect($item->fresh()->state)->toBe(ItemState::Unavailable);
    $this->actingAs($me)->put($url, ['state' => 'pending'])->assertStatus(303);
    expect($item->fresh()->state)->toBe(ItemState::Pending);
    $this->actingAs($me)->put($url, ['state' => 'gone'])->assertSessionHasErrors('state');
    $this->actingAs($me)->put($url, [])->assertSessionHasErrors('state');
});

it('throttles rapid marking at 240 per minute', function () {
    $me = F::user();
    $list = F::list($me, status: ListStatus::Locked);
    $item = F::item($list);
    $url = "/shopping/lists/{$list->id}/items/{$item->id}/state";

    $last = null;
    foreach (range(1, 241) as $i) {
        $last = $this->actingAs($me)->put($url, ['state' => $i % 2 ? 'bought' : 'pending'])->getStatusCode();
    }

    expect($last)->toBe(429);
});
