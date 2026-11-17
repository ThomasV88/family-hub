<?php

use App\Models\ShoppingItem;
use App\Models\ShoppingList;

$open = function (array $users) {
    $list = ShoppingList::factory()->create(['creator_id' => $users['parent']->id, 'responsible_id' => $users['parent']->id]);

    return ['list' => $list->id, 'item' => ShoppingItem::factory()->create(['list_id' => $list->id, 'position' => 1])->id];
};
$locked = function (array $users) {
    $list = ShoppingList::factory()->locked()->create(['creator_id' => $users['parent']->id, 'responsible_id' => $users['parent']->id]);

    return ['list' => $list->id, 'item' => ShoppingItem::factory()->create(['list_id' => $list->id, 'position' => 1])->id];
};

// Only the parent has a place on the list: the other editors answer 404, the guest preset 403 at the gate.
$parentOnly = ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 404, 'grandparent' => 404, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'shopping.items.store' => [
        'method' => 'POST',
        'uri' => '/shopping/lists/{list}/items',
        'params' => fn (array $users) => ['list' => $open($users)['list']],
        'data' => ['items' => [['name' => 'Matrix item', 'quantity' => '1']]],
        'expect' => $parentOnly,
    ],
    'shopping.items.update' => [
        'method' => 'PUT',
        'uri' => '/shopping/lists/{list}/items/{item}',
        'params' => $open,
        'data' => ['name' => 'Changed'],
        'expect' => $parentOnly,
    ],
    'shopping.items.destroy' => ['method' => 'DELETE', 'uri' => '/shopping/lists/{list}/items/{item}', 'params' => $open, 'expect' => $parentOnly],
    'shopping.items.state' => [
        'method' => 'PUT',
        'uri' => '/shopping/lists/{list}/items/{item}/state',
        'params' => $locked,
        'data' => ['state' => 'bought'],
        'expect' => $parentOnly,
    ],
];
