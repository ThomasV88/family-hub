<?php

use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Shopping\ItemState;

// A fresh open list created by the parent. Roles other than the parent have no place on it: they answer 404
// after the module gate lets them through (child, grandparent, guest preset) or 403 at the gate (admin, noGrants).
$open = fn (array $users) => ['list' => ShoppingList::factory()->create(['creator_id' => $users['parent']->id, 'responsible_id' => $users['parent']->id])->id];
$withItem = function (array $users) {
    $list = ShoppingList::factory()->create(['creator_id' => $users['parent']->id, 'responsible_id' => $users['parent']->id]);
    ShoppingItem::factory()->create(['list_id' => $list->id, 'position' => 1]);

    return ['list' => $list->id];
};
$locked = function (array $users) {
    $list = ShoppingList::factory()->locked()->create(['creator_id' => $users['parent']->id, 'responsible_id' => $users['parent']->id]);
    ShoppingItem::factory()->create(['list_id' => $list->id, 'position' => 1, 'state' => ItemState::Bought]);

    return ['list' => $list->id];
};

$view = ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200, 'grandparent' => 200, 'guestPreset' => 200, 'noGrants' => 403];
// Writes by the parent only: the other editors have no role on the list (404), the guest preset has no Shopping edit.
$parentOnly = ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 404, 'grandparent' => 404, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'shopping.index' => ['method' => 'GET', 'uri' => '/shopping', 'expect' => $view],
    'shopping.lists.store' => [
        'method' => 'POST',
        'uri' => '/shopping/lists',
        'data' => ['name' => 'Matrix list'],
        'expect' => ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303, 'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403],
    ],
    'shopping.lists.show' => [
        'method' => 'GET',
        'uri' => '/shopping/lists/{list}',
        'params' => $open,
        'expect' => ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 404, 'grandparent' => 404, 'guestPreset' => 404, 'noGrants' => 403],
    ],
    'shopping.lists.update' => [
        'method' => 'PUT',
        'uri' => '/shopping/lists/{list}',
        'params' => $open,
        'data' => ['name' => 'Renamed'],
        'expect' => $parentOnly,
    ],
    'shopping.lists.destroy' => ['method' => 'DELETE', 'uri' => '/shopping/lists/{list}', 'params' => $open, 'expect' => $parentOnly],
    'shopping.lists.lock' => [
        'method' => 'PUT',
        'uri' => '/shopping/lists/{list}/lock',
        'params' => $withItem,
        'data' => ['locked' => true],
        'expect' => $parentOnly,
    ],
    'shopping.lists.finish' => [
        'method' => 'POST',
        'uri' => '/shopping/lists/{list}/finish',
        'params' => $locked,
        'data' => [],
        'expect' => $parentOnly,
    ],
    'shopping.lists.copy' => [
        'method' => 'POST',
        'uri' => '/shopping/lists/{list}/copy',
        'params' => $open,
        'data' => ['name' => 'Copy', 'keep' => false],
        'expect' => $parentOnly,
    ],
];
