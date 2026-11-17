<?php

use App\Models\RecipePhoto;
use App\Models\ShoppingCatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

function rcpPutOld(string $path, int $minutes = 120): void
{
    Storage::disk('local')->put($path, 'x');
    touch(Storage::disk('local')->path($path), now()->subMinutes($minutes)->getTimestamp());
}

it('keeps referenced recipe photos, deletes old orphans and keeps fresh ones and other prefixes', function () {
    Storage::fake('local');
    $recipe = RecipeFixtures::recipe(RecipeFixtures::member());
    $photo = RecipePhoto::factory()->create([
        'recipe_id' => $recipe->id,
        'path' => "wish-images/recipes/{$recipe->id}/a-full.jpg",
        'thumb_path' => "wish-images/recipes/{$recipe->id}/a-thumb.jpg",
    ]);
    $item = ShoppingCatalogItem::factory()->create(['image_path' => 'wish-images/shopping/catalog/1/a-full.jpg', 'thumb_path' => 'wish-images/shopping/catalog/1/a-thumb.jpg']);
    foreach ([$photo->path, $photo->thumb_path, $item->image_path, $item->thumb_path] as $p) {
        rcpPutOld($p, 61);
    }
    rcpPutOld("wish-images/recipes/{$recipe->id}/orphan-full.jpg", 61);
    rcpPutOld('wish-images/recipes/999/orphan-thumb.jpg', 61);
    rcpPutOld("wish-images/recipes/{$recipe->id}/fresh-full.jpg", 10);

    $this->artisan('wishlist:prune')->assertSuccessful();

    foreach ([$photo->path, $photo->thumb_path, $item->image_path, $item->thumb_path, "wish-images/recipes/{$recipe->id}/fresh-full.jpg"] as $p) {
        Storage::disk('local')->assertExists($p);
    }
    Storage::disk('local')->assertMissing("wish-images/recipes/{$recipe->id}/orphan-full.jpg");
    Storage::disk('local')->assertMissing('wish-images/recipes/999/orphan-thumb.jpg');
});

it('has exactly one recipe photo location with an 8m limit and no extra 3m block', function () {
    $conf = file_get_contents(base_path('docker/nginx/snippets/laravel-locations.conf'));
    expect(substr_count($conf, 'recipes/[0-9]+/photos'))->toBe(1);
    preg_match('#location ~ \^/recipes/\[0-9\]\+/photos\$ \{\s*client_max_body_size (\w+);#', $conf, $m);
    expect($m[1] ?? null)->toBe('8m')->and(substr_count($conf, 'client_max_body_size 3m'))->toBe(1);
});
