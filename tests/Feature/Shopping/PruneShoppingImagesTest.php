<?php

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function putOld(string $path, int $minutes = 120): void
{
    Storage::disk('local')->put($path, 'x');
    touch(Storage::disk('local')->path($path), now()->subMinutes($minutes)->getTimestamp());
}

it('keeps referenced shopping images, deletes orphans and leaves profile photos alone', function () {
    Storage::fake('local');
    $item = ShoppingCatalogItem::factory()->create(['image_path' => 'wish-images/shopping/catalog/1/a-full.jpg', 'thumb_path' => 'wish-images/shopping/catalog/1/a-thumb.jpg']);
    $store = ShoppingStore::factory()->create(['logo_path' => 'wish-images/shopping/stores/1/a-full.jpg', 'logo_thumb_path' => 'wish-images/shopping/stores/1/a-thumb.jpg']);
    $user = User::factory()->create(['photo_path' => 'wish-images/profile-photos/1/p-full.jpg', 'photo_thumb_path' => 'wish-images/profile-photos/1/p-thumb.jpg']);
    foreach ([$item->image_path, $item->thumb_path, $store->logo_path, $store->logo_thumb_path, $user->photo_path, $user->photo_thumb_path] as $p) {
        putOld($p);
    }
    putOld('wish-images/shopping/catalog/1/orphan-full.jpg');
    putOld('wish-images/shopping/stores/1/orphan-full.jpg');
    putOld('wish-images/shopping/catalog/1/fresh-full.jpg', 5);

    $this->artisan('wishlist:prune')->assertSuccessful();

    foreach ([$item->image_path, $item->thumb_path, $store->logo_path, $store->logo_thumb_path, $user->photo_path, $user->photo_thumb_path, 'wish-images/shopping/catalog/1/fresh-full.jpg'] as $p) {
        Storage::disk('local')->assertExists($p);
    }
    Storage::disk('local')->assertMissing('wish-images/shopping/catalog/1/orphan-full.jpg');
    Storage::disk('local')->assertMissing('wish-images/shopping/stores/1/orphan-full.jpg');
});
