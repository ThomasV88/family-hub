<?php

use App\Models\RecipePhoto;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\RecipeFixtures;

// A shared recipe of the parent role with two photos (real files). Only the parent is its author.
$photos = function (array $users) {
    Storage::fake('local');
    $recipe = RecipeFixtures::sharedRecipe($users['parent']);
    $rows = [];
    foreach ([1, 2] as $i) {
        $dir = "wish-images/recipes/{$recipe->id}";
        $rows[] = RecipePhoto::factory()->create([
            'recipe_id' => $recipe->id, 'path' => "{$dir}/p{$i}-full.jpg", 'thumb_path' => "{$dir}/p{$i}-thumb.jpg",
            'position' => $i, 'is_main' => $i === 1,
        ]);
        Storage::disk('local')->put("{$dir}/p{$i}-full.jpg", ImageFixtures::jpeg());
        Storage::disk('local')->put("{$dir}/p{$i}-thumb.jpg", ImageFixtures::jpeg());
    }

    return ['recipe' => $recipe->id, 'photo' => $rows[1]->id];
};
$recipeOnly = fn (array $users) => ['recipe' => RecipeFixtures::sharedRecipe($users['parent'])->id];

// View: parent, child, grandparent and the guest preset have Recipes view; admin and noGrants have none (403).
$view = ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200, 'grandparent' => 200, 'guestPreset' => 200, 'noGrants' => 403];
// Write: only the author (parent) may; child and grandparent see the recipe but did not write it (403).
$write = ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];
// Upload and move: the matrix sends no file / direction, so the author reaches validation and is redirected back.
$validated = ['guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'recipes.photos.show' => ['method' => 'GET', 'uri' => '/recipes/{recipe}/photos/{photo}/thumb', 'params' => $photos, 'expect' => $view],
    'recipes.photos.store' => ['method' => 'POST', 'uri' => '/recipes/{recipe}/photos', 'params' => $recipeOnly, 'expect' => $validated],
    'recipes.photos.main' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}/photos/{photo}/main', 'params' => $photos, 'expect' => $write],
    'recipes.photos.move' => ['method' => 'PUT', 'uri' => '/recipes/{recipe}/photos/{photo}/position', 'params' => $photos, 'expect' => $validated],
    'recipes.photos.destroy' => ['method' => 'DELETE', 'uri' => '/recipes/{recipe}/photos/{photo}', 'params' => $photos, 'expect' => $write],
];
