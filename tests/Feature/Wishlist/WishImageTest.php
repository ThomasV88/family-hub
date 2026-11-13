<?php

use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use App\Models\WishUrl;
use App\Wishlist\Catalog\FetchWishImage;
use App\Wishlist\Catalog\WishPresenter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('local');
    Queue::fake();
    $this->parent = User::factory()->withPreset(Preset::Parent)->create();
    $this->child = User::factory()->withPreset(Preset::Child)->create();
    $this->grand = User::factory()->withPreset(Preset::Grandparent)->create();
});

function upload(string $bytes, string $name = 'x.jpg', string $mime = 'image/jpeg'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, $mime, null, true);
}

it('queues the image fetch once after commit when a wish is saved with links, without any HTTP call', function () {
    Http::fake();

    $this->actingAs($this->parent)->post('/wishlist/wishes', [
        'title' => 'Lamp', 'urls' => [['url' => 'https://shop.example/lamp']],
    ])->assertStatus(303);

    $wish = Wish::query()->sole();
    expect($wish->image_status)->toBe('pending');
    Queue::assertPushed(FetchWishImage::class, 1);
    Queue::assertPushed(FetchWishImage::class, fn ($job) => $job->wishId === $wish->id);
    Http::assertNothingSent();
});

it('queues again only when the links change and never over an uploaded image', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    WishUrl::factory()->create(['wish_id' => $wish->id, 'url' => 'https://shop.example/a', 'position' => 0]);

    $this->actingAs($this->parent)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'T', 'urls' => [['url' => 'https://shop.example/a']]]);
    Queue::assertNothingPushed();

    $this->actingAs($this->parent)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'T', 'urls' => [['url' => 'https://shop.example/b']]]);
    Queue::assertPushed(FetchWishImage::class, 1);

    $wish->forceFill(['image_source' => 'uploaded', 'image_status' => 'ready'])->save();
    $this->actingAs($this->parent)->patch("/wishlist/wishes/{$wish->id}", ['title' => 'T', 'urls' => [['url' => 'https://shop.example/c']]]);
    Queue::assertPushed(FetchWishImage::class, 1);
    expect($wish->fresh()->image_status)->toBe('ready');
});

it('does not queue for a wish without links', function () {
    $this->actingAs($this->parent)->post('/wishlist/wishes', ['title' => 'Plain'])->assertStatus(303);
    Queue::assertNothingPushed();
    expect(Wish::query()->sole()->image_status)->toBe('none');
});

it('stores an uploaded image for the owner as a metadata-free jpeg', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);

    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", ['image' => upload(ImageFixtures::jpegWithExif(6, 300, 200))])
        ->assertStatus(303);

    $wish->refresh();
    expect($wish->image_status)->toBe('ready')->and($wish->image_source)->toBe('uploaded')->and($wish->image_source_host)->toBeNull();
    $bytes = Storage::disk('local')->get($wish->image_path);
    expect($bytes)->not->toContain('Exif')->and(getimagesizefromstring($bytes)[2])->toBe(IMAGETYPE_JPEG)
        ->and([getimagesizefromstring($bytes)[0], getimagesizefromstring($bytes)[1]])->toBe([200, 300]);
});

it('lets a designated manager upload and refuses an unrelated edit-level member', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->child->id]);
    WishlistManager::factory()->create(['owner_id' => $this->child->id, 'manager_id' => $this->parent->id]);

    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", ['image' => upload(ImageFixtures::jpeg())])->assertStatus(303);
    expect($wish->fresh()->image_status)->toBe('ready');

    $this->actingAs($this->grand)->post("/wishlist/wishes/{$wish->id}/image", ['image' => upload(ImageFixtures::jpeg())])->assertForbidden();
    $this->actingAs($this->grand)->delete("/wishlist/wishes/{$wish->id}/image")->assertForbidden();
    $this->actingAs($this->grand)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertForbidden();
});

it('rejects bad uploads with translated error keys', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    $url = "/wishlist/wishes/{$wish->id}/image";
    $json = ['Accept' => 'application/json'];

    $this->actingAs($this->parent)->post($url, ['image' => upload(ImageFixtures::svg(), 'x.svg', 'image/svg+xml')], $json)
        ->assertStatus(422)->assertJsonPath('errors.image.0', 'wishlist.image.err_type');
    $this->actingAs($this->parent)->post($url, ['image' => upload(ImageFixtures::html(), 'x.jpg', 'image/jpeg')], $json)
        ->assertStatus(422)->assertJsonPath('errors.image.0', 'wishlist.image.err_type');
    $this->actingAs($this->parent)->post($url, ['image' => upload(str_repeat('a', 5 * 1024 * 1024 + 1024))], $json)
        ->assertStatus(422)->assertJsonPath('errors.image.0', 'wishlist.image.err_size');
    $this->actingAs($this->parent)->post($url, ['image' => 'not-a-file'], $json)
        ->assertStatus(422)->assertJsonPath('errors.image.0', 'wishlist.image.err_type');
    $this->actingAs($this->parent)->post($url, [], $json)->assertStatus(422);
    expect($wish->fresh()->image_status)->toBe('none');
});

it('throttles uploads at 20 a minute', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", [])->assertStatus(302);
    }
    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", [])->assertStatus(429);
});

it('removes the image and allows loading it from the link again', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    WishUrl::factory()->create(['wish_id' => $wish->id, 'url' => 'https://shop.example/a']);
    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", ['image' => upload(ImageFixtures::jpeg())]);
    $paths = [$wish->fresh()->image_path, $wish->fresh()->thumb_path];

    $this->actingAs($this->parent)->delete("/wishlist/wishes/{$wish->id}/image")
        ->assertStatus(303)->assertSessionHas('toast.key', 'wishlist.toast.image_removed');

    Storage::disk('local')->assertMissing($paths);
    expect($wish->fresh())->image_status->toBe('none')->image_source->toBeNull();

    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertStatus(303);
    expect($wish->fresh()->image_status)->toBe('pending');
    Queue::assertPushed(FetchWishImage::class, 1);
});

it('does nothing on load-from-link for a wish without links', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);

    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertStatus(303);

    Queue::assertNothingPushed();
    expect($wish->fresh()->image_status)->toBe('none');
});

it('throttles load-from-link at 10 a minute', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertStatus(303);
    }
    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image/fetch")->assertStatus(429);
});

it('serves the image to viewers with private no-cache, ETag, nosniff and Vary Cookie, and answers 304', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", ['image' => upload(ImageFixtures::jpeg())]);

    $response = $this->actingAs($this->child)->get("/wishlist/wishes/{$wish->id}/image/thumb")->assertOk();
    $cc = (string) $response->headers->get('Cache-Control');
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($cc)->toContain('private')->toContain('no-cache')->not->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Vary'))->toContain('Cookie');
    $etag = $response->headers->get('ETag');
    expect($etag)->not->toBeNull();

    $this->actingAs($this->child)->get("/wishlist/wishes/{$wish->id}/image/thumb", ['If-None-Match' => $etag])->assertStatus(304);
    $this->actingAs($this->child)->get("/wishlist/wishes/{$wish->id}/image/full")->assertOk();
});

it('refuses members without wishlist access, and answers 404 for no image or an unknown variant', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    $this->actingAs($this->parent)->get("/wishlist/wishes/{$wish->id}/image/thumb")->assertNotFound();

    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", ['image' => upload(ImageFixtures::jpeg())]);
    $this->actingAs($this->parent)->get("/wishlist/wishes/{$wish->id}/image/original")->assertNotFound();

    $guest = User::factory()->withPreset(Preset::Guest)->create();
    $this->actingAs($guest)->get("/wishlist/wishes/{$wish->id}/image/thumb")->assertForbidden();
});

it('puts image urls in the props only when ready, and deleting a wish removes its files', function () {
    $wish = Wish::factory()->create(['owner_id' => $this->parent->id]);
    $presenter = new WishPresenter;
    expect($presenter->present($wish->load('urls'))['image'])->toMatchArray(['thumb_url' => null, 'full_url' => null]);

    $this->actingAs($this->parent)->post("/wishlist/wishes/{$wish->id}/image", ['image' => upload(ImageFixtures::jpeg())]);
    $wish->refresh();
    $image = $presenter->present($wish->load('urls'))['image'];
    expect($image['thumb_url'])->toStartWith("/wishlist/wishes/{$wish->id}/image/thumb?v=")
        ->and($image['full_url'])->toStartWith("/wishlist/wishes/{$wish->id}/image/full?v=")
        ->and($image['status'])->toBe('ready');

    $paths = [$wish->image_path, $wish->thumb_path];
    $this->actingAs($this->parent)->delete("/wishlist/wishes/{$wish->id}")->assertStatus(303);
    Storage::disk('local')->assertMissing($paths);
});

it('keeps the image fetch out of web request code', function () {
    foreach (['app/Http/Controllers/Wishlist/Owner/WishImageController.php', 'app/Wishlist/Catalog/WishService.php'] as $file) {
        expect(file_get_contents(base_path($file)))->not->toContain('SafeFetcher')->not->toContain('Http::');
    }
});
