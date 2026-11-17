<?php

use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecipeFixtures;
use Tests\Support\RecipeShoppingFixtures as RS;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->author = RS::member();
    $this->me = RS::member();
    $this->recipe = RS::recipeWith($this->author, [['Cesnak', '3', null]]);
});

it('answers the preview JSON', function () {
    $this->actingAs($this->me)->getJson("/recipes/{$this->recipe->id}/shopping")->assertOk()
        ->assertJsonStructure(['baseServings', 'lists', 'stores', 'catalog', 'units', 'rows' => [['ingredientId', 'name', 'quantity', 'unitId', 'note', 'match' => ['tier', 'catalogItemId', 'catalogName'], 'suggestedUnitId']], 'canCreateList', 'limits' => ['nameMax', 'itemsMax']]);
});

it('commits and answers listId, listName and count', function () {
    $r = $this->actingAs($this->me)->postJson("/recipes/{$this->recipe->id}/shopping", [
        'target' => 'new', 'name' => 'Recept', 'items' => [['name' => 'Cesnak', 'quantity' => '3,5']],
    ])->assertOk()->assertJsonPath('listName', 'Recept')->assertJsonPath('count', 1);

    expect((string) ShoppingItem::query()->where('list_id', $r->json('listId'))->value('quantity'))->toBe('3.50');
});

it('answers 409 JSON with the Shopping key for a locked list and adds nothing', function () {
    $list = RS::lockedList($this->me);

    $this->actingAs($this->me)->postJson("/recipes/{$this->recipe->id}/shopping", [
        'target' => 'existing', 'list_id' => $list->id, 'items' => [['name' => 'Cesnak', 'quantity' => '1']],
    ])->assertStatus(409)->assertJsonPath('message', 'shopping.error.list_changed');
    expect($list->items()->count())->toBe(0);
});

it('validates the body with Shopping keys', function () {
    $url = "/recipes/{$this->recipe->id}/shopping";
    $this->actingAs($this->me)->postJson($url, ['target' => 'new', 'name' => 'L', 'items' => [['name' => 'x', 'quantity' => '1000']]])->assertJsonValidationErrors('items.0.quantity');
    $this->actingAs($this->me)->postJson($url, ['target' => 'new', 'name' => 'L', 'items' => array_fill(0, 120, ['name' => 'x', 'quantity' => '1'])])->assertJsonValidationErrors('items');
    $this->actingAs($this->me)->postJson($url, ['target' => 'new', 'name' => str_repeat('a', 81), 'items' => [['name' => 'x', 'quantity' => '1']]])->assertJsonValidationErrors('name');
    $this->actingAs($this->me)->postJson($url, ['target' => 'new', 'items' => [['name' => 'x', 'quantity' => '1']]])->assertJsonValidationErrors('name');
    $this->actingAs($this->me)->postJson($url, ['target' => 'existing', 'items' => [['name' => 'x', 'quantity' => '1']]])->assertJsonValidationErrors('list_id');
    $this->actingAs($this->me)->postJson($url, ['target' => 'new', 'name' => 'L', 'items' => [['name' => 'x', 'catalog_item_id' => 1, 'quantity' => '1']]])->assertJsonValidationErrors('items.0.name');
    $this->actingAs($this->me)->postJson($url, ['target' => 'new', 'name' => 'L', 'items' => [['quantity' => '1']]])->assertJsonValidationErrors('items.0.name');
    expect(ShoppingList::count())->toBe(0);
});

it('answers a private recipe of another member exactly like an unknown id', function () {
    $private = RecipeFixtures::privateRecipe($this->author);
    $body = ['target' => 'new', 'name' => 'L', 'items' => [['name' => 'x', 'quantity' => '1']]];

    foreach ([$private->id, 999999] as $id) {
        $this->actingAs($this->me)->getJson("/recipes/{$id}/shopping")->assertNotFound();
        $this->actingAs($this->me)->postJson("/recipes/{$id}/shopping", $body)->assertNotFound();
    }
    $this->actingAs($this->me)->postJson("/recipes/{$private->id}/shopping", ['target' => 'bogus'])->assertNotFound();
    expect(ShoppingList::count())->toBe(0);
});

it('refuses a member with Recipes view and Shopping view only', function () {
    $viewer = RS::member('view');
    $body = ['target' => 'new', 'name' => 'L', 'items' => [['name' => 'x', 'quantity' => '1']]];

    $this->actingAs($viewer)->getJson("/recipes/{$this->recipe->id}/shopping")->assertForbidden();
    $this->actingAs($viewer)->postJson("/recipes/{$this->recipe->id}/shopping", $body)->assertForbidden();
});
