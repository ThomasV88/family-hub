<?php

use Tests\Support\RecipeFixtures;

// A shared recipe of the parent role (only the parent is its author). A fresh one is built for every role.
$shared = fn (array $users) => ['recipe' => RecipeFixtures::sharedRecipe($users['parent'])->id];

$body = [
    'title' => 'Matrix soup',
    'visibility' => 'private',
    'ingredients' => [['name' => 'Tomato', 'quantity' => '2']],
    'steps' => [['body' => 'Boil']],
];

// View: parent, child, grandparent and the guest preset have Recipes view; admin and noGrants have none (403).
$view = ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200, 'grandparent' => 200, 'guestPreset' => 200, 'noGrants' => 403];
// Authoring page: Recipes edit needed, no record yet.
$edit = ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200, 'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403];
// Create: every editor may create a recipe of their own.
$create = ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303, 'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403];
// Author only: the editors who did not write the shared recipe see it (403, not 404); the guest preset stops at the gate.
$author = ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];
$authorWrite = ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'recipes.index' => ['method' => 'GET', 'uri' => '/recipes', 'expect' => $view],
    'recipes.create' => ['method' => 'GET', 'uri' => '/recipes/create', 'expect' => $edit],
    'recipes.store' => ['method' => 'POST', 'uri' => '/recipes', 'data' => $body, 'expect' => $create],
    'recipes.show' => ['method' => 'GET', 'uri' => '/recipes/{recipe}', 'params' => $shared, 'expect' => $view],
    'recipes.edit' => ['method' => 'GET', 'uri' => '/recipes/{recipe}/edit', 'params' => $shared, 'expect' => $author],
    'recipes.update' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}', 'params' => $shared, 'data' => $body, 'expect' => $authorWrite],
    'recipes.destroy' => ['method' => 'DELETE', 'uri' => '/recipes/{recipe}', 'params' => $shared, 'expect' => $authorWrite],
    'recipes.visibility' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}/visibility', 'params' => $shared, 'data' => ['visibility' => 'private'], 'expect' => $authorWrite],
];
