<?php

use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\MedicalDocumentStore;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\TestCase;

/**
 * Owner decision WR-12: stored medical images are re-encoded at up to 3600 px on the long edge and JPEG quality 90 (was 2400 px
 * and 82). EXIF and GPS are still stripped and the original is still not kept. The decode is bounded by a memory estimate
 * so a 3600 px output cannot push a decode past the app container's 128M PHP limit.
 */
uses(TestCase::class);

const MIL_SUBJECT = 'a1b2c3d4-e5f6-4a1b-8c2d-0123456789ab';

/** The luminance quantisation table's first value of a baseline JPEG: 3 for quality 90 and 6 for quality 82 (libjpeg scaling). */
function milQuantizer(string $jpeg): int
{
    $at = strpos($jpeg, "\xFF\xDB");

    return ord($jpeg[$at + 5]);
}

function milProbe(int $w, int $h, int $orientation): array
{
    $output = [];
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=128M '.escapeshellarg(base_path('tests/Support/medical-image-memory-probe.php')).' '.$w.' '.$h.' '.$orientation.' 2>&1', $output, $code);
    $result = json_decode((string) end($output), true);
    expect($code)->toBe(0)->and($result)->toBeArray();

    return $result;
}

it('pins the medical image limits', function () {
    expect(config('medical.image_full_px'))->toBe(3600)
        ->and(config('medical.image_jpeg_quality'))->toBe(90)
        ->and(config('medical.image_thumb_px'))->toBe(480);
});

describe('store', function () {
    beforeEach(fn () => Storage::fake('medical'));

    it('stores the image at 90 quality, 3600 px at most, without EXIF or GPS, and keeps no original', function () {
        $out = app(MedicalDocumentStore::class)->put(MIL_SUBJECT, ImageFixtures::jpegWithExif(6, 4000, 1500));

        $disk = Storage::disk('medical');
        $full = $disk->get($out['path']);
        $thumb = $disk->get($out['thumb_path']);
        expect(milQuantizer($full))->toBe(3)->and(milQuantizer($thumb))->toBe(3)
            ->and([$out['width'], $out['height']])->toBe([1350, 3600])
            ->and($full)->not->toContain('Exif')->and($full)->not->toContain('GPS')->and($thumb)->not->toContain('Exif')
            // Only the sanitized full image and its thumbnail exist: no original.
            ->and($disk->allFiles())->toHaveCount(2)
            ->and(array_map(fn ($f) => preg_replace('#^.*-(full|thumb)\.jpg$#', '$1', $f), $disk->allFiles()))->toEqualCanonicalizing(['full', 'thumb']);
    });

    it('does not upscale a smaller image', function () {
        $out = app(MedicalDocumentStore::class)->put(MIL_SUBJECT, ImageFixtures::jpeg(1000, 700));

        expect([$out['width'], $out['height']])->toBe([1000, 700]);
    });
});

it('keeps the wishlist images at quality 82', function () {
    $out = (new ImageProcessor)->process(ImageFixtures::jpeg(400, 300), 5 * 1048576);

    expect(milQuantizer($out->fullBytes))->toBe(6);
});

describe('decode memory', function () {
    it('accepts a 12 MP phone photo and rejects a near-square 13 MP image before decoding', function () {
        $budget = (int) config('medical.image_decode_budget_bytes');
        $processor = new ImageProcessor;
        $args = fn (string $bytes) => [$bytes, (int) config('medical.document_max_bytes'), 3600, 480, 90, $budget];

        $phone = $processor->process(...$args(ImageFixtures::jpeg(4032, 3024)));
        expect([$phone->width, $phone->height])->toBe([3600, 2700]);

        $reason = fn (string $bytes) => (function () use ($processor, $args, $bytes) {
            try {
                $processor->process(...$args($bytes));
            } catch (ImageRejected $e) {
                return $e->reason;
            }

            return null;
        })();
        $photo = ImageFixtures::jpeg(4032, 3024);
        expect($reason(ImageFixtures::pngBomb(3600, 3600)))->toBe('too_many_pixels')
            // The same 12 MP photo as a file of just under 10 MB does not fit: the bytes count towards the estimate.
            ->and($reason($photo.str_repeat("\0", 10 * 1048576 - strlen($photo) - 1)))->toBe('too_many_pixels');
    });

    it('turns a rejected decode into the too_many_pixels document reason', function () {
        Storage::fake('medical');

        expect(fn () => app(MedicalDocumentStore::class)->put(MIL_SUBJECT, ImageFixtures::pngBomb(3600, 3600)))
            ->toThrow(DocumentRejected::class);
    });

    it('processes a 12 MP orientation 6 photo inside the 128M memory_limit with room to spare', function () {
        $result = milProbe(4032, 3024, 6);

        fwrite(STDERR, sprintf("\nmedical 12MP peak delta %.1f MiB, total %.1f MiB, rss %.1f MiB (memory_limit %s)\n", $result['peak'] / 1048576, $result['peak_total'] / 1048576, ($result['rss_peak'] ?? 0) / 1048576, $result['limit']));
        expect($result['limit'])->toBe('128M')
            ->and([$result['width'], $result['height']])->toBe([2700, 3600])
            // Real photos are a few MB larger than the synthetic fixture: keep at least 10 MiB of the 128 MiB free.
            ->and($result['peak_total'])->toBeLessThan((128 - 10) * 1048576);
    });

    it('rejects a near-square 13 MP image in the 128M process without running out of memory', function () {
        $result = milProbe(3600, 3600, 6);

        expect($result['rejected'])->toBe('too_many_pixels');
    });
});
