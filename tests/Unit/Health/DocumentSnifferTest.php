<?php

use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\DocumentSniffer;
use App\Medical\Documents\MedicalDocumentStore;
use App\Wishlist\Catalog\ImageProcessor;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\TestCase;

uses(TestCase::class);

const MD_SUBJECT = 'a1b2c3d4-e5f6-4a1b-8c2d-0123456789ab';

function mdSniff(string $bytes): array|string
{
    try {
        return (new DocumentSniffer)->sniff($bytes);
    } catch (DocumentRejected $e) {
        return $e->reason;
    }
}

function mdPut(string $bytes): array|string
{
    try {
        return app(MedicalDocumentStore::class)->put(MD_SUBJECT, $bytes);
    } catch (DocumentRejected $e) {
        return $e->reason;
    }
}

describe('sniffer', function () {
    it('accepts a PDF, a PDF with a script tail, JPEG, PNG and WebP', function () {
        expect(mdSniff(MedicalFileFixtures::pdf())['kind'])->toBe('pdf')
            ->and(mdSniff(MedicalFileFixtures::pdfWithScriptTail())['kind'])->toBe('pdf')
            ->and(mdSniff(ImageFixtures::jpeg())['kind'])->toBe('image')
            ->and(mdSniff(ImageFixtures::pngTransparent())['kind'])->toBe('image')
            ->and(mdSniff(ImageFixtures::webp())['kind'])->toBe('image');
    });

    it('accepts HEIC and HEIF brands and takes the type from the bytes, not the name', function () {
        $heic = mdSniff(MedicalFileFixtures::heic());
        $heif = mdSniff(MedicalFileFixtures::heic('mif1'));

        expect($heic['kind'])->toBe('heic')->and($heic['ext'])->toBe('heic')->and($heic['mime'])->toBe('image/heic')
            ->and($heif['kind'])->toBe('heic')->and($heif['mime'])->toBe('image/heif')
            ->and(mdSniff(MedicalFileFixtures::mislabeledJpeg())['kind'])->toBe('image');
    });

    it('refuses everything else', function (string $label, string $bytes) {
        expect(mdSniff($bytes))->toBe('unsupported_type', $label);
    })->with([
        'leading junk pdf' => ['junk', fn () => MedicalFileFixtures::pdfWithLeadingJunk()],
        'spaces then pdf' => ['spaces', fn () => MedicalFileFixtures::pdfAfterSpaces()],
        'gif' => ['gif', fn () => ImageFixtures::gif()],
        'bmp' => ['bmp', fn () => MedicalFileFixtures::bmp()],
        'svg' => ['svg', fn () => ImageFixtures::svg()],
        'avif' => ['avif', fn () => MedicalFileFixtures::avif()],
        'bad brand' => ['brand', fn () => MedicalFileFixtures::heic('xxxx')],
        'truncated ftyp' => ['trunc', fn () => pack('N', 24).'ftyp'.'hei'],
        'empty' => ['empty', ''],
    ]);
});

describe('store', function () {
    beforeEach(function () {
        Storage::fake('medical');
    });

    it('re-encodes an oriented JPEG without EXIF or GPS and within the size caps', function () {
        $out = mdPut(ImageFixtures::jpegWithExif(6, 3000, 1000));

        $full = Storage::disk('medical')->get($out['path']);
        $thumb = Storage::disk('medical')->get($out['thumb_path']);
        $fullInfo = getimagesizefromstring($full);
        $thumbInfo = getimagesizefromstring($thumb);

        expect($out['kind'])->toBe('image')
            ->and($full)->not->toContain('Exif')
            ->and($thumb)->not->toContain('Exif')
            ->and(max($fullInfo[0], $fullInfo[1]))->toBeLessThanOrEqual(2400)
            ->and(max($thumbInfo[0], $thumbInfo[1]))->toBeLessThanOrEqual(480)
            ->and($fullInfo[1])->toBeGreaterThan($fullInfo[0])
            ->and($out['width'])->toBe($fullInfo[0])->and($out['height'])->toBe($fullInfo[1])
            ->and($out['size_bytes'])->toBe(strlen($full))
            ->and($out['sha256'])->toBe(hash('sha256', $full));
    });

    it('drops a PDF or PHP tail appended to a JPEG', function () {
        $out = mdPut(MedicalFileFixtures::jpegWithTail());

        $stored = Storage::disk('medical')->get($out['path']).Storage::disk('medical')->get($out['thumb_path']);
        expect($stored)->not->toContain('%PDF-')->and($stored)->not->toContain('<?php');
    });

    it('refuses a pixel bomb before decoding and oversize bytes', function () {
        expect(mdPut(ImageFixtures::pngBomb(20000, 20000)))->toBe('too_many_pixels')
            ->and(mdPut(str_repeat('a', (int) config('medical.document_max_bytes') + 1)))->toBe('too_large')
            ->and(Storage::disk('medical')->allFiles())->toBe([]);
    });

    it('stores PDF and HEIC originals byte-identical with ULID names', function () {
        $pdf = MedicalFileFixtures::pdf('x');
        $heic = MedicalFileFixtures::heic();
        $a = mdPut($pdf);
        $b = mdPut($heic);

        expect($a['ext'])->toBe('pdf')->and($a['thumb_path'])->toBeNull()
            ->and(Storage::disk('medical')->get($a['path']))->toBe($pdf)
            ->and($b['ext'])->toBe('heic')->and(Storage::disk('medical')->get($b['path']))->toBe($heic)
            ->and($a['path'])->toMatch('#^'.MD_SUBJECT.'/[0-9A-Z]{26}\.pdf$#')
            ->and($b['path'])->toMatch('#^'.MD_SUBJECT.'/[0-9A-Z]{26}\.heic$#');
        $img = mdPut(ImageFixtures::jpeg());
        expect($img['path'])->toMatch('#^'.MD_SUBJECT.'/[0-9A-Z]{26}-full\.jpg$#')
            ->and($img['thumb_path'])->toMatch('#^'.MD_SUBJECT.'/[0-9A-Z]{26}-thumb\.jpg$#');
    });

    it('leaves no file behind when the second write fails', function () {
        $calls = 0;
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturnUsing(function () use (&$calls) {
            return ++$calls === 1;
        });
        $disk->shouldReceive('delete')->once()->with(Mockery::on(fn ($p) => count($p) === 2))->andReturn(true);
        Storage::set('medical', $disk);

        expect(mdPut(ImageFixtures::jpeg()))->toBe('write_failed');
    });

    it('guards paths with the shape check and touches no disk on a bad path', function () {
        $store = app(MedicalDocumentStore::class);
        $ok = $store->put(MD_SUBJECT, MedicalFileFixtures::pdf());
        $u = MD_SUBJECT;
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        expect($store->absolutePath($ok['path']))->toBeString();
        $store->delete([$ok['path']]);
        expect(Storage::disk('medical')->allFiles())->toBe([]);

        $keep = $store->put(MD_SUBJECT, MedicalFileFixtures::pdf());
        foreach (['../etc/passwd', "$u/../x.jpg", "$u/$ulid.php", "$u/$ulid-big.jpg", "$u/sub/$ulid.jpg", "/$u/$ulid.jpg", strtoupper($u)."/$ulid.jpg", '', "$u/$ulid.pdf\n"] as $bad) {
            expect(fn () => $store->absolutePath($bad))->toThrow(InvalidArgumentException::class);
            expect(fn () => $store->delete([$keep['path'], $bad]))->toThrow(InvalidArgumentException::class);
        }
        expect(Storage::disk('medical')->exists($keep['path']))->toBeTrue();

        foreach (['..', '', 'abc', "$u/x"] as $bad) {
            expect(fn () => $store->deleteSubjectDirectory($bad))->toThrow(InvalidArgumentException::class);
        }
        expect(Storage::disk('medical')->exists($keep['path']))->toBeTrue();
    });

    it('lists stored files and subject directories and deletes a directory', function () {
        $store = app(MedicalDocumentStore::class);
        $a = $store->put(MD_SUBJECT, MedicalFileFixtures::pdf());

        expect($store->files())->toHaveCount(1)
            ->and($store->files()[0]['path'])->toBe($a['path'])
            ->and($store->files()[0]['modifiedAt'])->toBeInt()
            ->and($store->subjectDirectories())->toHaveCount(1)
            ->and($store->subjectDirectories()[0]['subjectId'])->toBe(MD_SUBJECT);

        $store->deleteSubjectDirectory(MD_SUBJECT);
        expect($store->files())->toBe([])->and($store->subjectDirectories())->toBe([]);
    });
});

it('honours explicit pixel parameters in ImageProcessor and keeps the defaults', function () {
    $jpeg = ImageFixtures::jpeg(400, 200);
    $explicit = (new ImageProcessor)->process($jpeg, 5_000_000, 100, 50);
    $default = (new ImageProcessor)->process($jpeg, 5_000_000);

    expect($explicit->width)->toBe(100)->and(getimagesizefromstring($explicit->thumbBytes)[0])->toBe(50)
        ->and($default->width)->toBe(400);
});
