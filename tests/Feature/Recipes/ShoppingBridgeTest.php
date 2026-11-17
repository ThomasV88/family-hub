<?php

use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Recipes\Shopping\RecipeShoppingBridge;
use App\Shopping\ItemService;
use App\Shopping\ListStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecipeFixtures;
use Tests\Support\RecipeShoppingFixtures as RS;
use Tests\Support\ShoppingFixtures;

uses(RefreshDatabase::class);

function recipeBridge(): RecipeShoppingBridge
{
    return app(RecipeShoppingBridge::class);
}

it('previews only open lists the actor may edit, with matches and suggested units', function () {
    $me = RS::member();
    $other = RS::member();
    $open = RS::openList($me);
    $asContributor = RS::openList($other, $me);
    $asViewer = ShoppingFixtures::list($other, null, ListStatus::Open, [], [$me]);
    RS::lockedList($me);
    RS::doneList($me);
    RS::openList($other);
    $milk = RS::catalog('Mlieko', 'l');
    ShoppingFixtures::item($open, ['catalog_item_id' => $milk->id, 'name' => 'Mlieko', 'unit_id' => RecipeFixtures::unitId('l'), 'quantity' => '2.00']);
    $recipe = RS::recipeWith($other, [['mlieko', '0.5', null], ['soľ', null, null], ['Cesnak', '3', RecipeFixtures::unitId('g')]]);

    $p = recipeBridge()->preview($me, $recipe->id);

    expect(collect($p['lists'])->pluck('id')->sort()->values()->all())->toBe(collect([$open->id, $asContributor->id])->sort()->values()->all())
        ->and(collect($p['lists'])->firstWhere('id', $open->id)['pending'])->toBe([['catalogItemId' => $milk->id, 'unitId' => RecipeFixtures::unitId('l'), 'quantity' => '2']])
        ->and($p['baseServings'])->toBe(4)
        ->and($p['rows'][0])->toMatchArray(['name' => 'mlieko', 'quantity' => '0.5', 'suggestedUnitId' => RecipeFixtures::unitId('l')])
        ->and($p['rows'][0]['match']['tier'])->toBe('exact')
        ->and($p['rows'][1])->toMatchArray(['quantity' => null, 'suggestedUnitId' => RecipeFixtures::unitId('pcs')])
        ->and($p['rows'][1]['match']['tier'])->toBe('none')
        ->and($p['rows'][2]['suggestedUnitId'])->toBe(RecipeFixtures::unitId('g'))
        ->and($asViewer->id)->not->toBeIn(collect($p['lists'])->pluck('id')->all())
        ->and($p['limits'])->toBe(['nameMax' => 80, 'itemsMax' => 80]);
});

it('commits to a new list with the actor responsible and the store', function () {
    $me = RS::member();
    $recipe = RS::recipeWith($me, [['Cesnak', '3', null]]);
    $store = ShoppingStore::query()->create(['name' => 'Tesco']);

    $r = recipeBridge()->commit($me, $recipe->id, 'new', null, '  Večera   z receptu ', $store->id, [['name' => 'Cesnak', 'quantity' => '3'], ['name' => 'Soľ', 'quantity' => '1', 'unit_id' => RecipeFixtures::unitId('pcs')]]);

    $list = ShoppingList::findOrFail($r['listId']);
    expect($r)->toMatchArray(['listName' => 'Večera z receptu', 'count' => 2])
        ->and($list->responsible_id)->toBe($me->id)->and($list->creator_id)->toBe($me->id)->and($list->store_id)->toBe($store->id)
        ->and($list->items()->count())->toBe(2);
});

it('merges catalog rows into pending lines and never merges typed rows', function () {
    $me = RS::member();
    $milk = RS::catalog('Mlieko', 'l');
    $list = RS::openList($me);
    ShoppingFixtures::item($list, ['catalog_item_id' => $milk->id, 'name' => 'Mlieko', 'unit_id' => RecipeFixtures::unitId('l'), 'quantity' => '1.00']);
    ShoppingFixtures::item($list, ['catalog_item_id' => null, 'name' => 'Soľ', 'quantity' => '1.00']);
    $recipe = RS::recipeWith($me, [['Mlieko', '1', null]]);

    recipeBridge()->commit($me, $recipe->id, 'existing', $list->id, null, null, [
        ['catalog_item_id' => $milk->id, 'quantity' => '0.5', 'unit_id' => RecipeFixtures::unitId('l')],
        ['name' => 'Soľ', 'quantity' => '2'],
    ]);

    $items = $list->items()->orderBy('id')->get();
    expect($items)->toHaveCount(3)->and((string) $items[0]->quantity)->toBe('1.50')->and($items[2]->name)->toBe('Soľ');
});

it('adds nothing and creates no list when one row is invalid', function () {
    $me = RS::member();
    $recipe = RS::recipeWith($me, [['Cesnak', '3', null]]);
    $before = ShoppingList::count();

    $errors = ShoppingFixtures::errors(fn () => recipeBridge()->commit($me, $recipe->id, 'new', null, 'Nový', null, [['name' => 'Ok', 'quantity' => '1'], ['name' => 'Big', 'quantity' => '1000']]));

    expect($errors)->toHaveKey('items.1.quantity')->and(ShoppingList::count())->toBe($before)->and(ShoppingItem::count())->toBe(0);
});

it('answers 409 and adds nothing for a list locked after the preview', function () {
    $me = RS::member();
    // The list the dialog offered is locked by the time the member commits.
    $list = RS::lockedList($me);
    $recipe = RS::recipeWith($me, [['Cesnak', '3', null]]);

    expect(ShoppingFixtures::status(fn () => recipeBridge()->commit($me, $recipe->id, 'existing', $list->id, null, null, [['name' => 'Cesnak', 'quantity' => '3']])))->toBe(409)
        ->and($list->items()->count())->toBe(0);
});

it('refuses a foreign list, a hidden recipe and a viewer', function () {
    $me = RS::member();
    $other = RS::member();
    $theirs = RS::openList($other);
    $recipe = RS::recipeWith($me, [['Cesnak', '3', null]]);
    $hidden = RecipeFixtures::privateRecipe($other);
    $row = [['name' => 'x', 'quantity' => '1']];

    expect(ShoppingFixtures::status(fn () => recipeBridge()->commit($me, $recipe->id, 'existing', $theirs->id, null, null, $row)))->toBe(404)
        ->and(ShoppingFixtures::status(fn () => recipeBridge()->commit($me, $hidden->id, 'new', null, 'L', null, $row)))->toBe(404);

    $viewer = RS::member('view');
    expect(ShoppingFixtures::status(fn () => recipeBridge()->commit($viewer, $recipe->id, 'new', null, 'L', null, $row)))->toBe(403);
});

it('adds 80 rows in chunks below the 50-per-call limit of ItemService', function () {
    $me = RS::member();
    $recipe = RS::recipeWith($me, [['Cesnak', '3', null]]);
    $rows = array_map(fn ($i) => ['name' => "Item $i", 'quantity' => '1'], range(1, 80));

    // ItemService refuses more than 50 rows per call, so 80 rows only arrive when the bridge sends two chunks.
    expect(ShoppingFixtures::errors(fn () => app(ItemService::class)->add($me, RS::openList($me)->id, $rows)))->toHaveKey('items');

    $r = recipeBridge()->commit($me, $recipe->id, 'new', null, 'Big', null, $rows);

    expect($r['count'])->toBe(80)->and(ShoppingList::findOrFail($r['listId'])->items()->count())->toBe(80);
});
