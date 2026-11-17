<?php

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

$catalogImage = function (array $users) {
    Storage::fake('local');
    $item = ShoppingCatalogItem::factory()->create(['image_path' => 'wish-images/shopping/catalog/1/a-full.jpg', 'thumb_path' => 'wish-images/shopping/catalog/1/a-thumb.jpg']);
    Storage::disk('local')->put($item->thumb_path, ImageFixtures::jpeg());

    return ['item' => $item->id, 'variant' => 'thumb'];
};
$storeImage = function (array $users) {
    Storage::fake('local');
    $store = ShoppingStore::factory()->create(['logo_path' => 'wish-images/shopping/stores/1/a-full.jpg', 'logo_thumb_path' => 'wish-images/shopping/stores/1/a-thumb.jpg']);
    Storage::disk('local')->put($store->logo_thumb_path, ImageFixtures::jpeg());

    return ['store' => $store->id, 'variant' => 'thumb'];
};
$catalogItem = fn (array $users) => ['item' => ShoppingCatalogItem::factory()->create()->id];
$store = fn (array $users) => ['store' => ShoppingStore::factory()->create()->id];

// View: parent, child, grandparent and the guest preset have Shopping; admin and noGrants have none (403).
$view = ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200, 'grandparent' => 200, 'guestPreset' => 200, 'noGrants' => 403];
// Upload: the matrix posts no file, so editors reach validation and are redirected back (302).
$upload = ['guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 302, 'grandparent' => 302, 'guestPreset' => 403, 'noGrants' => 403];
$remove = ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303, 'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'shopping.catalog.image.show' => ['method' => 'GET', 'uri' => '/shopping/catalog/{item}/image/{variant}', 'params' => $catalogImage, 'expect' => $view],
    'shopping.catalog.image.store' => ['method' => 'POST', 'uri' => '/shopping/catalog/{item}/image', 'params' => $catalogItem, 'expect' => $upload],
    'shopping.catalog.image.destroy' => ['method' => 'DELETE', 'uri' => '/shopping/catalog/{item}/image', 'params' => $catalogItem, 'expect' => $remove],
    'shopping.stores.image.show' => ['method' => 'GET', 'uri' => '/shopping/stores/{store}/image/{variant}', 'params' => $storeImage, 'expect' => $view],
    'shopping.stores.image.store' => ['method' => 'POST', 'uri' => '/shopping/stores/{store}/image', 'params' => $store, 'expect' => $upload],
    'shopping.stores.image.destroy' => ['method' => 'DELETE', 'uri' => '/shopping/stores/{store}/image', 'params' => $store, 'expect' => $remove],
];
