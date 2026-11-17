<?php

use App\Access\AccessLevel;
use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use App\Shopping\Images\ShoppingImageStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\ShoppingFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->editor = ShoppingFixtures::user(AccessLevel::Edit);
    $this->viewer = ShoppingFixtures::user(AccessLevel::View);
});

function shoppingUpload(string $bytes, string $name = 'pic.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('re-encodes a catalog image without EXIF and replaces the old files after the swap', function () {
    $item = ShoppingCatalogItem::factory()->create();

    $this->actingAs($this->editor)->post("/shopping/catalog/{$item->id}/image", ['image' => shoppingUpload(ImageFixtures::jpegWithExif(6, 800, 600))])
        ->assertStatus(303)->assertSessionHas('toast');
    $first = $item->fresh();
    expect($first->image_version)->toBe(1)->and($first->image_path)->toStartWith("wish-images/shopping/catalog/{$item->id}/");
    foreach ([$first->image_path, $first->thumb_path] as $path) {
        $bytes = Storage::disk('local')->get($path);
        expect(str_starts_with($bytes, "\xFF\xD8"))->toBeTrue()->and(str_contains($bytes, 'Exif'))->toBeFalse();
    }

    $this->actingAs($this->editor)->post("/shopping/catalog/{$item->id}/image", ['image' => shoppingUpload(ImageFixtures::jpeg())])->assertStatus(303);
    $second = $item->fresh();
    expect($second->image_version)->toBe(2)->and($second->image_path)->not->toBe($first->image_path);
    Storage::disk('local')->assertMissing($first->image_path);
    Storage::disk('local')->assertMissing($first->thumb_path);
    Storage::disk('local')->assertExists($second->image_path);

    $this->actingAs($this->editor)->delete("/shopping/catalog/{$item->id}/image")->assertStatus(303);
    expect($item->fresh()->image_path)->toBeNull();
    Storage::disk('local')->assertMissing($second->image_path);
});

it('stores a transparent store logo as a JPEG', function () {
    $store = ShoppingStore::factory()->create();

    $this->actingAs($this->editor)->post("/shopping/stores/{$store->id}/image", ['image' => shoppingUpload(ImageFixtures::pngTransparent())])->assertStatus(303);

    $row = $store->fresh();
    expect($row->logo_path)->toStartWith("wish-images/shopping/stores/{$store->id}/")
        ->and(substr(Storage::disk('local')->get($row->logo_path), 0, 2))->toBe("\xFF\xD8");
});

it('rejects an oversized logo and a text file renamed to png', function () {
    $store = ShoppingStore::factory()->create();

    $big = UploadedFile::fake()->create('big.png', 2049);
    $this->actingAs($this->editor)->post("/shopping/stores/{$store->id}/image", ['image' => $big])
        ->assertSessionHasErrors(['image' => 'shopping.image.err_size']);
    $this->actingAs($this->editor)->post("/shopping/stores/{$store->id}/image", ['image' => shoppingUpload('just text, not an image', 'x.png')])
        ->assertSessionHasErrors(['image' => 'shopping.image.err_type']);
    expect($store->fresh()->logo_path)->toBeNull();
});

it('serves private no-cache images with an ETag and answers 304 on revalidation', function () {
    $item = ShoppingCatalogItem::factory()->create();
    $this->actingAs($this->editor)->post("/shopping/catalog/{$item->id}/image", ['image' => shoppingUpload(ImageFixtures::jpeg())]);

    foreach ([$this->editor, $this->viewer] as $user) {
        $response = $this->actingAs($user)->get("/shopping/catalog/{$item->id}/image/thumb")->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
            ->and($response->headers->get('Vary'))->toContain('Cookie')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }
    $etag = $this->actingAs($this->viewer)->get("/shopping/catalog/{$item->id}/image/thumb")->headers->get('ETag');
    expect($etag)->not->toBeNull();
    $this->actingAs($this->viewer)->get("/shopping/catalog/{$item->id}/image/thumb", ['If-None-Match' => $etag])->assertStatus(304);
});

it('answers the same 404 for a missing image and an unknown id, and refuses members without access', function () {
    $item = ShoppingCatalogItem::factory()->create();
    $none = ShoppingFixtures::user(AccessLevel::None);

    $a = $this->actingAs($this->editor)->get("/shopping/catalog/{$item->id}/image/thumb")->assertNotFound();
    $b = $this->actingAs($this->editor)->get('/shopping/catalog/999999/image/thumb')->assertNotFound();
    expect($a->getContent())->toBe($b->getContent());
    $this->actingAs($this->editor)->get('/shopping/stores/999999/image/full')->assertNotFound();
    $this->actingAs($none)->get("/shopping/catalog/{$item->id}/image/thumb")->assertForbidden();
});

it('refuses writes to a view-only member and a 404 for an unknown id', function () {
    $item = ShoppingCatalogItem::factory()->create();

    $this->actingAs($this->viewer)->post("/shopping/catalog/{$item->id}/image", ['image' => shoppingUpload(ImageFixtures::jpeg())])->assertForbidden();
    $this->actingAs($this->viewer)->delete("/shopping/catalog/{$item->id}/image")->assertForbidden();
    $this->actingAs($this->editor)->post('/shopping/catalog/999999/image', [])->assertNotFound();
    $this->actingAs($this->editor)->delete('/shopping/stores/999999/image')->assertNotFound();
});

it('removes the freshly written files when the row disappears before the swap', function () {
    $item = ShoppingCatalogItem::factory()->create();
    $ghost = ShoppingCatalogItem::query()->find($item->id);
    $item->delete();

    expect(fn () => app(ShoppingImageStore::class)->storeCatalogImage($ghost, ImageFixtures::jpeg()))
        ->toThrow(ModelNotFoundException::class);

    expect(Storage::disk('local')->allFiles('wish-images/shopping'))->toBe([]);
});
