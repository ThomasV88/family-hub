<?php

use App\Models\Wish;
use App\Models\WishUrl;
use App\Wishlist\Catalog\FetchWishImage;
use App\Wishlist\Fetch\SafeFetcher;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDnsResolver;
use Tests\Support\ImageFixtures;

beforeEach(function () {
    Storage::fake('local');
});

function useFetcher(array $responses): MockHandler
{
    $mock = new MockHandler($responses);
    app()->instance(SafeFetcher::class, new SafeFetcher(
        new FakeDnsResolver(['shop.example' => ['93.184.216.34'], 'cdn.example' => ['93.184.216.35']]),
        null,
        $mock,
    ));

    return $mock;
}

function runFetch(int $wishId): void
{
    app()->call([new FetchWishImage($wishId), 'handle']);
}

function pendingWish(array $urls = ['https://shop.example/item']): Wish
{
    $wish = Wish::factory()->create(['image_status' => 'pending']);
    foreach ($urls as $position => $url) {
        WishUrl::factory()->create(['wish_id' => $wish->id, 'url' => $url, 'position' => $position]);
    }

    return $wish;
}

const PAGE_OG = '<html><head><meta property="og:image" content="https://cdn.example/p.jpg"></head></html>';

it('loads the og:image in the worker and marks the wish ready', function () {
    $wish = pendingWish();
    useFetcher([
        new Response(200, ['Content-Type' => 'text/html'], PAGE_OG),
        new Response(200, ['Content-Type' => 'image/jpeg'], ImageFixtures::jpegWithExif(6, 800, 600)),
    ]);

    runFetch($wish->id);

    $wish->refresh();
    expect($wish->image_status)->toBe('ready')->and($wish->image_source)->toBe('fetched')
        ->and($wish->image_source_host)->toBe('shop.example');
    Storage::disk('local')->assertExists([$wish->image_path, $wish->thumb_path]);
});

it('marks failed when the page has no image candidates', function () {
    $wish = pendingWish();
    useFetcher([new Response(200, ['Content-Type' => 'text/html'], '<html><body>nothing</body></html>')]);

    runFetch($wish->id);

    expect($wish->fresh())->image_status->toBe('failed')->image_path->toBeNull();
});

it('marks failed when the page cannot be fetched', function () {
    $wish = pendingWish();
    useFetcher([new Response(500)]);

    runFetch($wish->id);

    expect($wish->fresh()->image_status)->toBe('failed');
});

it('tries the next candidate when one is rejected and gives up after three', function () {
    $wish = pendingWish();
    $page = '<meta property="og:image" content="https://cdn.example/a.jpg"><meta property="og:image" content="https://cdn.example/b.jpg">';
    useFetcher([
        new Response(200, [], $page),
        new Response(200, [], ImageFixtures::html()),
        new Response(200, [], ImageFixtures::jpeg()),
    ]);
    runFetch($wish->id);
    expect($wish->fresh())->image_status->toBe('ready')->image_source->toBe('fetched');

    $other = pendingWish();
    $many = implode('', array_map(fn ($n) => '<meta property="og:image" content="https://cdn.example/'.$n.'.jpg">', range(1, 5)));
    $mock = useFetcher([
        new Response(200, [], $many),
        new Response(200, [], ImageFixtures::html()),
        new Response(200, [], ImageFixtures::svg()),
        new Response(200, [], ImageFixtures::random()),
        new Response(200, [], ImageFixtures::jpeg()),
    ]);
    runFetch($other->id);
    expect($other->fresh()->image_status)->toBe('failed')->and($mock->count())->toBe(1);
});

it('leaves a manually uploaded image untouched and does not fetch', function () {
    $wish = pendingWish();
    $wish->forceFill(['image_source' => 'uploaded', 'image_status' => 'ready', 'image_path' => 'wish-images/x/a-full.jpg', 'thumb_path' => 'wish-images/x/a-thumb.jpg'])->save();
    $mock = useFetcher([new Response(200, [], PAGE_OG)]);

    runFetch($wish->id);

    expect($mock->count())->toBe(1)->and($wish->fresh())->image_source->toBe('uploaded')->image_path->toBe('wish-images/x/a-full.jpg');
});

it('is a no-op for a deleted wish', function () {
    $mock = useFetcher([new Response(200, [], PAGE_OG)]);
    runFetch(999999);
    expect($mock->count())->toBe(1);
});

it('does not overwrite an upload that arrived while the image was loading', function () {
    $wish = pendingWish();
    $mock = useFetcher([
        new Response(200, [], PAGE_OG),
        function () use ($wish) {
            $wish->forceFill(['image_source' => 'uploaded', 'image_status' => 'ready', 'image_path' => 'wish-images/x/u-full.jpg', 'thumb_path' => 'wish-images/x/u-thumb.jpg'])->save();

            return new Response(200, [], ImageFixtures::jpeg());
        },
    ]);

    runFetch($wish->id);

    expect($wish->fresh())->image_source->toBe('uploaded')->image_path->toBe('wish-images/x/u-full.jpg');
});

it('logs only the wish id and a reason code', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });
    $wish = pendingWish(['https://shop.example/secret-path?token=abc']);
    useFetcher([new Response(500)]);

    runFetch($wish->id);

    $joined = implode("\n", $logged);
    expect($joined)->toContain('wish_image_fetch')->toContain((string) $wish->id)
        ->not->toContain('secret-path')->not->toContain('token=abc')->not->toContain('shop.example');
});

it('marks failed through the failed() hook for unexpected errors', function () {
    $wish = pendingWish();
    (new FetchWishImage($wish->id))->failed(new RuntimeException('boom https://secret.example'));
    expect($wish->fresh()->image_status)->toBe('failed');
});
