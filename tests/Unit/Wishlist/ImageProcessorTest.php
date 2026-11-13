<?php

use App\Models\Wish;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use App\Wishlist\Catalog\WishImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\TestCase;

uses(TestCase::class);

const IMG_MAX = 5 * 1024 * 1024;

function imgRejectedReason(string $bytes, int $max = IMG_MAX): string
{
    try {
        (new ImageProcessor)->process($bytes, $max);
    } catch (ImageRejected $e) {
        return $e->reason;
    }

    return 'accepted';
}

function jpegDims(string $jpeg): array
{
    $info = getimagesizefromstring($jpeg);

    return [$info[0], $info[1], $info[2]];
}

it('builds fixtures that really carry orientation and GPS', function () {
    $exif = exif_read_data('data://image/jpeg;base64,'.base64_encode(ImageFixtures::jpegWithExif(6)));
    expect($exif['Orientation'])->toBe(6)->and($exif)->toHaveKey('GPSLatitude');
});

it('rotates an orientation 6 photo upright and strips all metadata', function () {
    $out = (new ImageProcessor)->process(ImageFixtures::jpegWithExif(6, 640, 480), IMG_MAX);

    [$w, $h, $type] = jpegDims($out->fullBytes);
    expect($type)->toBe(IMAGETYPE_JPEG)->and([$w, $h])->toBe([480, 640])
        ->and([$out->width, $out->height])->toBe([480, 640])
        ->and($out->fullBytes)->not->toContain('Exif')
        ->and($out->thumbBytes)->not->toContain('Exif');
    $reread = @exif_read_data('data://image/jpeg;base64,'.base64_encode($out->fullBytes));
    expect(is_array($reread) ? array_key_exists('GPSLatitude', $reread) : false)->toBeFalse();
    expect(max(jpegDims($out->thumbBytes)[0], jpegDims($out->thumbBytes)[1]))->toBeLessThanOrEqual(480);
});

it('caps the full image at 1280 and the thumb at 480 without upscaling', function () {
    $big = (new ImageProcessor)->process(ImageFixtures::jpeg(2000, 1000), IMG_MAX);
    expect(jpegDims($big->fullBytes))->toMatchArray([1280, 640])
        ->and(jpegDims($big->thumbBytes))->toMatchArray([480, 240]);

    $small = (new ImageProcessor)->process(ImageFixtures::jpeg(100, 50), IMG_MAX);
    expect(jpegDims($small->fullBytes))->toMatchArray([100, 50])->and(jpegDims($small->thumbBytes))->toMatchArray([100, 50]);
});

it('applies every orientation to the right output shape', function (int $orientation, bool $swapped) {
    $out = (new ImageProcessor)->process(ImageFixtures::jpegWithExif($orientation, 200, 100), IMG_MAX);
    expect([$out->width, $out->height])->toBe($swapped ? [100, 200] : [200, 100]);
})->with([[1, false], [2, false], [3, false], [4, false], [5, true], [6, true], [7, true], [8, true]]);

it('processes a 12 MP orientation 6 photo within the 128M budget', function () {
    // A separate process under the production memory_limit: the result must not depend on suite memory.
    $output = [];
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=128M '.escapeshellarg(base_path('tests/Support/image-memory-probe.php')).' 2>&1', $output, $code);
    $result = json_decode((string) end($output), true);

    expect($code)->toBe(0)->and($result)->toBeArray();
    fwrite(STDERR, sprintf("\n12MP peak delta: %.1f MiB (memory_limit %s)\n", $result['peak'] / 1048576, $result['limit']));
    expect($result['limit'])->toBe('128M')->and($result['peak'])->toBeLessThan(72 * 1048576)
        ->and([$result['width'], $result['height']])->toBe([960, 1280]);
});

it('rejects a 13.12 MP header before decoding', function () {
    expect(imgRejectedReason(ImageFixtures::pngBomb(4100, 3200)))->toBe('too_many_pixels');
});

it('flattens transparency onto white, turns gif into a still jpeg and decodes webp', function () {
    $png = (new ImageProcessor)->process(ImageFixtures::pngTransparent(), IMG_MAX);
    $img = imagecreatefromstring($png->fullBytes);
    $rgb = imagecolorat($img, 16, 16);
    expect(($rgb >> 16) & 255)->toBeGreaterThan(245)->and(($rgb >> 8) & 255)->toBeGreaterThan(245)->and($rgb & 255)->toBeGreaterThan(245);

    expect(jpegDims((new ImageProcessor)->process(ImageFixtures::gif(), IMG_MAX)->fullBytes)[2])->toBe(IMAGETYPE_JPEG);
    expect(jpegDims((new ImageProcessor)->process(ImageFixtures::webp(), IMG_MAX)->fullBytes)[2])->toBe(IMAGETYPE_JPEG);
});

it('rejects a decompression bomb before any decode', function () {
    $bytes = ImageFixtures::pngBomb(30000, 30000);
    memory_reset_peak_usage();
    $baseline = memory_get_usage();
    expect(imgRejectedReason($bytes))->toBe('too_many_pixels');
    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(10 * 1048576);
});

it('rejects svg, polyglot, html and random bytes as unsupported', function (string $bytes) {
    expect(imgRejectedReason($bytes))->toBe('unsupported_type');
})->with([
    'svg' => fn () => ImageFixtures::svg(),
    'polyglot' => fn () => ImageFixtures::polyglot(),
    'html' => fn () => ImageFixtures::html(),
    'random' => fn () => ImageFixtures::random(),
]);

it('rejects oversized bytes before decoding', function () {
    expect(imgRejectedReason(ImageFixtures::jpeg(64, 48), 100))->toBe('too_large');
});

describe('WishImageStore', function () {
    uses(RefreshDatabase::class);

    beforeEach(fn () => Storage::fake('local'));

    it('stores two random-named files, replaces the old ones and removes them', function () {
        $wish = Wish::factory()->create();
        $store = new WishImageStore;
        $img = (new ImageProcessor)->process(ImageFixtures::jpeg(), IMG_MAX);

        $store->put($wish, $img, 'uploaded', null);
        $first = [$wish->image_path, $wish->thumb_path];
        expect($wish->image_path)->toStartWith("wish-images/{$wish->id}/")->toEndWith('-full.jpg')
            ->and($wish->thumb_path)->toEndWith('-thumb.jpg')
            ->and($wish->image_status)->toBe('ready')->and($wish->image_source)->toBe('uploaded');
        Storage::disk('local')->assertExists($first);

        $store->put($wish, $img, 'fetched', 'shop.example');
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists([$wish->image_path, $wish->thumb_path]);
        expect($wish->fresh()->image_source_host)->toBe('shop.example');

        $current = [$wish->image_path, $wish->thumb_path];
        $store->remove($wish);
        Storage::disk('local')->assertMissing($current);
        expect($wish->fresh())->image_status->toBe('none')->image_path->toBeNull()->thumb_path->toBeNull()->image_source->toBeNull();
    });

    it('sweeps only unreferenced files older than the cutoff', function () {
        $wish = Wish::factory()->create();
        $store = new WishImageStore;
        $store->put($wish, (new ImageProcessor)->process(ImageFixtures::jpeg(), IMG_MAX), 'uploaded', null);
        $disk = Storage::disk('local');
        $disk->put('wish-images/999/orphan-full.jpg', 'x');
        $disk->put('wish-images/999/fresh-full.jpg', 'x');
        touch($disk->path('wish-images/999/orphan-full.jpg'), time() - 3600);

        expect($store->sweepOrphans(30))->toBe(1);
        $disk->assertMissing('wish-images/999/orphan-full.jpg');
        $disk->assertExists('wish-images/999/fresh-full.jpg');
        $disk->assertExists([$wish->image_path, $wish->thumb_path]);
    });
});
