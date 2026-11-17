<?php

use Tests\Support\RecipeFixtures;

// A shared recipe of the parent role; Recipes view plus Shopping edit are both needed. The guest preset has
// Shopping view only (403 at the gate), admin and noGrants hold no module grant.
$shared = fn (array $users) => ['recipe' => RecipeFixtures::sharedRecipe($users['parent'])->id];
$access = ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200, 'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'recipes.shopping.preview' => ['method' => 'GET', 'uri' => '/recipes/{recipe}/shopping', 'params' => $shared, 'expect' => $access],
    'recipes.shopping.store' => [
        'method' => 'POST',
        'uri' => '/recipes/{recipe}/shopping',
        'params' => $shared,
        'data' => ['target' => 'new', 'name' => 'Matrix list', 'items' => [['name' => 'Tomato', 'quantity' => '2']]],
        'expect' => $access,
    ],
];
