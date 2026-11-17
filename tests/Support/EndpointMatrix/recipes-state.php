<?php

use Tests\Support\RecipeFixtures;

// A shared recipe of the parent role. Marks are per member, so Recipes view is enough (guest preset included).
$shared = fn (array $users) => ['recipe' => RecipeFixtures::sharedRecipe($users['parent'])->id];
$withLines = function (array $users) {
    $recipe = RecipeFixtures::sharedRecipe($users['parent']);

    return ['recipe' => $recipe->id, 'ingredient' => $recipe->ingredients()->value('id'), 'step' => $recipe->steps()->value('id')];
};

$mark = ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303, 'grandparent' => 303, 'guestPreset' => 303, 'noGrants' => 403];

return [
    'recipes.ingredients.tick' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}/ingredients/{ingredient}/tick', 'params' => $withLines, 'data' => ['ticked' => true], 'expect' => $mark],
    'recipes.steps.done' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}/steps/{step}/done', 'params' => $withLines, 'data' => ['done' => true], 'expect' => $mark],
    'recipes.favourite' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}/favourite', 'params' => $shared, 'data' => ['favourite' => true], 'expect' => $mark],
    'recipes.note' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}/note', 'params' => $shared, 'data' => ['note' => 'Less salt'], 'expect' => $mark],
    'recipes.servings' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}/servings', 'params' => $shared, 'data' => ['servings' => 6], 'expect' => $mark],
    'recipes.progress.reset' => ['method' => 'DELETE', 'uri' => '/recipes/{recipe}/progress', 'params' => $shared, 'expect' => $mark],
];
