<?php

use App\Models\ShoppingList;

// Only the parent has a place on the list: the other editors answer 404, the guest preset 403 at the gate.
return [
    'shopping.lists.mail' => [
        'method' => 'POST',
        'uri' => '/shopping/lists/{list}/mail',
        'params' => fn (array $users) => ['list' => ShoppingList::factory()->create(['creator_id' => $users['parent']->id, 'responsible_id' => $users['parent']->id])->id],
        'data' => ['addresses' => ['matrix-recipient@example.test']],
        'expect' => ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 404, 'grandparent' => 404, 'guestPreset' => 403, 'noGrants' => 403],
    ],
];
