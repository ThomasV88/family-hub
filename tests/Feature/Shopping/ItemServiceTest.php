<?php

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingUnit;
use App\Shopping\ItemService;
use App\Shopping\ItemState;
use App\Shopping\ListStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShoppingFixtures as F;

function items(): ItemService
{
    return app(ItemService::class);
}

function unit(string $key): int
{
    return (int) ShoppingUnit::query()->where('key', $key)->value('id');
}

it('adds a catalog item and a free-text item in one call and appends positions', function () {
    $creator = F::user();
    $list = F::list($creator);
    $milk = ShoppingCatalogItem::factory()->create(['name' => 'Milk', 'default_unit_id' => unit('l')]);

    $result = items()->add($creator, $list->id, [
        ['catalog_item_id' => $milk->id, 'quantity' => '2'],
        ['name' => 'Fresh bread', 'quantity' => '1.5', 'unit_id' => unit('kg'), 'note' => ' crusty '],
    ]);

    $rows = ShoppingItem::query()->where('list_id', $list->id)->orderBy('position')->get();
    expect($result)->toBe(['added' => 2, 'merged' => []])
        ->and($rows->pluck('name')->all())->toBe(['Milk', 'Fresh bread'])
        ->and($rows[0]->unit_id)->toBe(unit('l'))
        ->and($rows[0]->quantity)->toBe('2.00')
        ->and($rows[1]->note)->toBe('crusty')
        ->and($rows[1]->position)->toBeGreaterThan($rows[0]->position)
        ->and($rows[1]->state)->toBe(ItemState::Pending)
        ->and($rows[1]->created_by)->toBe($creator->id);
});

it('merges the same catalog item with the same unit into the pending row and reports it', function () {
    $creator = F::user();
    $list = F::list($creator);
    $milk = ShoppingCatalogItem::factory()->create(['name' => 'Milk']);

    items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'quantity' => '2', 'unit_id' => unit('l')]]);
    $result = items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'quantity' => '1.25', 'unit_id' => unit('l')]]);

    expect($result)->toBe(['added' => 0, 'merged' => ['Milk']])
        ->and(ShoppingItem::where('list_id', $list->id)->count())->toBe(1)
        ->and(ShoppingItem::where('list_id', $list->id)->value('quantity'))->toBe('3.25');
});

it('refuses a merge that would pass 999.99 and keeps the stored line', function () {
    $creator = F::user();
    $list = F::list($creator);
    $milk = ShoppingCatalogItem::factory()->create(['name' => 'Milk']);

    items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'quantity' => '999']]);

    expect(F::errors(fn () => items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'quantity' => '500']])))
        ->toHaveKey('items.0.quantity')
        ->and(ShoppingItem::where('list_id', $list->id)->value('quantity'))->toBe('999.00');
});

it('appends the note of a merged add instead of dropping it', function () {
    $creator = F::user();
    $list = F::list($creator);
    $milk = ShoppingCatalogItem::factory()->create(['name' => 'Milk']);

    items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'quantity' => '1']]);
    items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'quantity' => '1', 'note' => 'lactose free']]);
    expect(ShoppingItem::where('list_id', $list->id)->value('note'))->toBe('lactose free');

    items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'quantity' => '1', 'note' => 'semi-skimmed']]);
    items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'quantity' => '1', 'note' => 'semi-skimmed']]);

    expect(ShoppingItem::where('list_id', $list->id)->value('note'))->toBe('lactose free; semi-skimmed')
        ->and(ShoppingItem::where('list_id', $list->id)->count())->toBe(1);
});

it('refuses a merged note that would pass the note limit', function () {
    $creator = F::user();
    $list = F::list($creator);
    $milk = ShoppingCatalogItem::factory()->create(['name' => 'Milk']);

    items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'note' => str_repeat('a', 150)]]);

    expect(F::errors(fn () => items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'note' => str_repeat('b', 60)]])))
        ->toHaveKey('items.0.note');
});

it('does not fail when the same new catalog name is saved concurrently', function () {
    $creator = F::user();
    $list = F::list($creator);
    $raced = false;
    // Simulate the other request winning the race: its row is committed on another connection right before our insert.
    ShoppingCatalogItem::creating(function (ShoppingCatalogItem $item) use (&$raced) {
        if (! $raced) {
            $raced = true;
            DB::connectUsing('racer', config('database.connections.'.config('database.default')))
                ->table('shopping_catalog_items')->insert(['name' => $item->name, 'name_key' => $item->name_key, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    try {
        $result = items()->add($creator, $list->id, [['name' => 'Rare spice', 'quantity' => '1', 'save_to_catalog' => true]]);

        expect($result['added'])->toBe(1)
            ->and(ShoppingCatalogItem::query()->where('name', 'Rare spice')->count())->toBe(1)
            ->and(ShoppingItem::where('list_id', $list->id)->value('catalog_item_id'))->toBe(ShoppingCatalogItem::query()->where('name', 'Rare spice')->value('id'));
    } finally {
        // The racing row is committed on its own connection and is referenced by the item: end the test transaction first.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::connection('racer')->table('shopping_catalog_items')->where('name', 'Rare spice')->delete();
    }
});

it('refuses more items than a list may hold', function () {
    $creator = F::user();
    $list = F::list($creator);
    config(['shopping.items_per_list' => 3]);
    items()->add($creator, $list->id, [['name' => 'a'], ['name' => 'b']]);

    expect(F::errors(fn () => items()->add($creator, $list->id, [['name' => 'c'], ['name' => 'd']])))->toHaveKey('items')
        ->and(ShoppingItem::where('list_id', $list->id)->count())->toBe(2);

    items()->add($creator, $list->id, [['name' => 'c']]);
    expect(ShoppingItem::where('list_id', $list->id)->count())->toBe(3);
});

it('adds a new row for a different unit', function () {
    $creator = F::user();
    $list = F::list($creator);
    $milk = ShoppingCatalogItem::factory()->create(['name' => 'Milk']);

    items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'unit_id' => unit('l')]]);
    $result = items()->add($creator, $list->id, [['catalog_item_id' => $milk->id, 'unit_id' => unit('ml')]]);

    expect($result['added'])->toBe(1)
        ->and(ShoppingItem::where('list_id', $list->id)->count())->toBe(2);
});

it('does not merge into a row that is no longer pending', function () {
    $creator = F::user();
    $list = F::list($creator);
    $milk = ShoppingCatalogItem::factory()->create(['name' => 'Milk']);
    F::item($list, ['catalog_item_id' => $milk->id, 'name' => 'Milk', 'state' => ItemState::Bought]);

    expect(items()->add($creator, $list->id, [['catalog_item_id' => $milk->id]])['added'])->toBe(1);
});

it('saves a free-text item to the catalog once and links the second call to it', function () {
    $creator = F::user();
    $list = F::list($creator);

    items()->add($creator, $list->id, [['name' => 'Mlieko', 'save_to_catalog' => true, 'unit_id' => unit('l')]]);
    $second = items()->add($creator, $list->id, [['name' => 'MLIEKO', 'save_to_catalog' => true, 'unit_id' => unit('ml')]]);

    $catalog = ShoppingCatalogItem::all();
    expect($catalog)->toHaveCount(1)
        ->and($catalog[0]->name)->toBe('Mlieko')
        ->and($catalog[0]->default_unit_id)->toBe(unit('l'))
        ->and($catalog[0]->created_by)->toBe($creator->id)
        ->and($second['added'])->toBe(1)
        ->and(ShoppingItem::where('list_id', $list->id)->pluck('catalog_item_id')->unique()->all())->toBe([$catalog[0]->id]);
});

it('keeps a plain free-text item out of the catalog', function () {
    $creator = F::user();
    $list = F::list($creator);

    items()->add($creator, $list->id, [['name' => 'Candles']]);

    expect(ShoppingCatalogItem::count())->toBe(0)
        ->and(ShoppingItem::where('list_id', $list->id)->value('catalog_item_id'))->toBeNull();
});

it('refuses more than 50 items, none and invalid input', function () {
    $creator = F::user();
    $list = F::list($creator);
    $many = array_fill(0, 51, ['name' => 'x']);

    expect(F::errors(fn () => items()->add($creator, $list->id, $many)))->toHaveKey('items')
        ->and(F::errors(fn () => items()->add($creator, $list->id, [])))->toHaveKey('items')
        ->and(F::errors(fn () => items()->add($creator, $list->id, [['name' => ' ']])))->toHaveKey('items.0.name')
        ->and(F::errors(fn () => items()->add($creator, $list->id, [['name' => 'x', 'quantity' => '0']])))->toHaveKey('items.0.quantity')
        ->and(F::errors(fn () => items()->add($creator, $list->id, [['name' => 'x', 'quantity' => '1000']])))->toHaveKey('items.0.quantity')
        ->and(F::errors(fn () => items()->add($creator, $list->id, [['name' => 'x', 'quantity' => '1e2']])))->toHaveKey('items.0.quantity')
        ->and(F::errors(fn () => items()->add($creator, $list->id, [['name' => 'x', 'unit_id' => 999999]])))->toHaveKey('items.0.unit_id')
        ->and(F::errors(fn () => items()->add($creator, $list->id, [['catalog_item_id' => 999999]])))->toHaveKey('items.0.catalog_item_id')
        ->and(ShoppingItem::count())->toBe(0);
});

it('rolls back the whole add when one entry is invalid', function () {
    $creator = F::user();
    $list = F::list($creator);

    F::errors(fn () => items()->add($creator, $list->id, [['name' => 'ok'], ['name' => 'bad', 'quantity' => '-1']]));

    expect(ShoppingItem::count())->toBe(0);
});

it('lets a contributor add, update and remove while open', function () {
    $creator = F::user();
    $contrib = F::user();
    $list = F::list($creator, contributors: [$contrib]);

    items()->add($contrib, $list->id, [['name' => 'Eggs', 'quantity' => '6']]);
    $item = ShoppingItem::where('list_id', $list->id)->firstOrFail();

    $updated = items()->update($contrib, $list->id, $item->id, ['name' => 'Free eggs', 'quantity' => '10', 'note' => null, 'unit_id' => unit('pcs')]);
    expect($updated->name)->toBe('Free eggs')->and($updated->quantity)->toBe('10.00')->and($updated->unit_id)->toBe(unit('pcs'));

    items()->remove($contrib, $list->id, $item->id);
    expect(ShoppingItem::count())->toBe(0);
});

it('refuses item changes on a locked or done list for everyone', function (ListStatus $status) {
    $creator = F::user();
    $contrib = F::user();
    $list = F::list($creator, status: $status, contributors: [$contrib]);
    $item = F::item($list);

    foreach ([$creator, $contrib] as $actor) {
        expect(F::status(fn () => items()->add($actor, $list->id, [['name' => 'x']])))->toBe(409)
            ->and(F::status(fn () => items()->update($actor, $list->id, $item->id, ['name' => 'y'])))->toBe(409)
            ->and(F::status(fn () => items()->remove($actor, $list->id, $item->id)))->toBe(409);
    }
    expect($item->fresh()->name)->toBe($item->name);
})->with([ListStatus::Locked, ListStatus::Done]);

it('forbids a viewer and hides the list from an outsider', function () {
    $creator = F::user();
    $viewer = F::user();
    $list = F::list($creator, viewers: [$viewer]);
    $item = F::item($list);

    expect(F::status(fn () => items()->add($viewer, $list->id, [['name' => 'x']])))->toBe(403)
        ->and(F::status(fn () => items()->update($viewer, $list->id, $item->id, ['name' => 'y'])))->toBe(403)
        ->and(F::status(fn () => items()->remove($viewer, $list->id, $item->id)))->toBe(403)
        ->and(F::status(fn () => items()->add(F::user(), $list->id, [['name' => 'x']])))->toBe(404)
        ->and(ShoppingItem::count())->toBe(1);
});

it('does not reach an item through another list', function () {
    $creator = F::user();
    $list = F::list($creator);
    $other = F::list($creator);
    $item = F::item($other);

    expect(fn () => items()->update($creator, $list->id, $item->id, ['name' => 'y']))->toThrow(ModelNotFoundException::class);
});
