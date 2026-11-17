<?php

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function rawList(array $over = []): int
{
    return DB::table('shopping_lists')->insertGetId(array_merge([
        'name' => 'L', 'creator_id' => User::factory()->create()->id,
        'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ], $over));
}

function rawItem(array $over = []): int
{
    return DB::table('shopping_items')->insertGetId(array_merge([
        'list_id' => ShoppingList::factory()->create()->id, 'name' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ], $over));
}

it('seeds only the nine built-in units and no catalog or stores', function () {
    expect(ShoppingUnit::orderBy('position')->pluck('key')->all())
        ->toBe(['pcs', 'g', 'kg', 'ml', 'l', 'pack', 'bunch', 'tbsp', 'tsp'])
        ->and(ShoppingCatalogItem::count())->toBe(0)
        ->and(ShoppingStore::count())->toBe(0)
        ->and(ShoppingUnit::where('key', 'pcs')->first()->isBuiltIn())->toBeTrue();
});

it('rejects an unknown list status', function () {
    rawList(['status' => 'paused']);
})->throws(QueryException::class);

it('rejects an unknown item state', function () {
    rawItem(['state' => 'lost']);
})->throws(QueryException::class);

it('rejects an unknown member role', function () {
    DB::table('shopping_list_members')->insert([
        'list_id' => ShoppingList::factory()->create()->id, 'user_id' => User::factory()->create()->id, 'role' => 'owner',
    ]);
})->throws(QueryException::class);

it('rejects quantity zero', function () {
    rawItem(['quantity' => 0]);
})->throws(QueryException::class);

it('rejects quantity 1000', function () {
    rawItem(['quantity' => 1000]);
})->throws(QueryException::class);

it('rejects a done list without done_at', function () {
    rawList(['status' => 'done']);
})->throws(QueryException::class);

it('rejects done_at on a list that is not done', function () {
    rawList(['status' => 'open', 'done_at' => now()]);
})->throws(QueryException::class);

it('rejects a locked list without locked_at', function () {
    rawList(['status' => 'locked']);
})->throws(QueryException::class);

it('rejects a source list without a source kind', function () {
    rawList(['source_list_id' => ShoppingList::factory()->create()->id]);
})->throws(QueryException::class);

it('rejects a unit with both a key and names', function () {
    DB::table('shopping_units')->insert(['key' => 'x', 'name_en' => 'a', 'name_sk' => 'b', 'name_pl' => 'c', 'created_at' => now(), 'updated_at' => now()]);
})->throws(QueryException::class);

it('rejects a unit with only one name', function () {
    DB::table('shopping_units')->insert(['name_en' => 'a', 'created_at' => now(), 'updated_at' => now()]);
})->throws(QueryException::class);

it('rejects duplicate custom unit names ignoring case', function () {
    ShoppingUnit::factory()->create(['name_en' => 'Bag']);
    ShoppingUnit::factory()->create(['name_en' => 'bag']);
})->throws(QueryException::class);

it('rejects catalog item names that differ only by case or accents', function (string $second) {
    ShoppingCatalogItem::create(['name' => 'Mlieko']);
    ShoppingCatalogItem::create(['name' => $second]);
})->with(['mlieko', 'Mliéko', ' MLIEKO '])->throws(QueryException::class);

it('rejects store names that differ only by case or accents', function () {
    ShoppingStore::create(['name' => 'Tesco']);
    ShoppingStore::create(['name' => 'TÉSCO']);
})->throws(QueryException::class);

it('allows at most one carry-over list per source but several copies', function () {
    $source = ShoppingList::factory()->done()->create();
    ShoppingList::factory()->create(['source_list_id' => $source->id, 'source_kind' => 'copy']);
    ShoppingList::factory()->create(['source_list_id' => $source->id, 'source_kind' => 'copy']);
    ShoppingList::factory()->create(['source_list_id' => $source->id, 'source_kind' => 'carry']);
    expect(fn () => ShoppingList::factory()->create(['source_list_id' => $source->id, 'source_kind' => 'carry']))
        ->toThrow(QueryException::class);
});

it('keeps list items when a catalog item is deleted', function () {
    $catalog = ShoppingCatalogItem::create(['name' => 'Chlieb']);
    $item = ShoppingItem::factory()->create(['catalog_item_id' => $catalog->id, 'name' => 'Chlieb', 'quantity' => '2.50']);
    $catalog->delete();
    $item->refresh();
    expect($item->catalog_item_id)->toBeNull()->and($item->name)->toBe('Chlieb')->and($item->quantity)->toBe('2.50');
});

it('nulls unit references when a custom unit is deleted', function () {
    $unit = ShoppingUnit::factory()->create();
    $catalog = ShoppingCatalogItem::create(['name' => 'Ryža', 'default_unit_id' => $unit->id]);
    $item = ShoppingItem::factory()->create(['unit_id' => $unit->id]);
    $unit->delete();
    expect($item->refresh()->unit_id)->toBeNull()->and($catalog->refresh()->default_unit_id)->toBeNull();
});

it('keeps the list and its store name when a store is deleted', function () {
    $store = ShoppingStore::create(['name' => 'Lidl']);
    $list = ShoppingList::factory()->create(['store_id' => $store->id, 'store_name' => 'Lidl']);
    $store->delete();
    expect($list->refresh()->store_id)->toBeNull()->and($list->store_name)->toBe('Lidl');
});

it('cascades items and members when a list is deleted', function () {
    $list = ShoppingList::factory()->create();
    ShoppingItem::factory()->create(['list_id' => $list->id]);
    DB::table('shopping_list_members')->insert(['list_id' => $list->id, 'user_id' => User::factory()->create()->id, 'role' => 'viewer']);
    $list->delete();
    expect(ShoppingItem::count())->toBe(0)->and(DB::table('shopping_list_members')->count())->toBe(0);
});

it('refuses to delete the creator of a list', function () {
    $list = ShoppingList::factory()->create();
    expect(fn () => DB::table('users')->where('id', $list->creator_id)->delete())->toThrow(QueryException::class);
});

it('reads quantity back as a two-decimal string', function () {
    $item = ShoppingItem::factory()->create(['quantity' => 1.5]);
    expect($item->fresh()->quantity)->toBe('1.50');
});

it('produces valid rows in every list state', function () {
    expect(ShoppingList::factory()->locked()->create()->status->value)->toBe('locked')
        ->and(ShoppingList::factory()->done()->create()->done_at)->not->toBeNull()
        ->and(ShoppingItem::factory()->bought()->create()->state->value)->toBe('bought')
        ->and(ShoppingItem::factory()->unavailable()->create()->state->value)->toBe('unavailable');
});
