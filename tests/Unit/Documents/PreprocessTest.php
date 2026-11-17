<?php

use App\Medical\Ai\ExtractionValidator;
use App\Medical\Documents\Preprocess\DocumentPreprocessor;
use App\Medical\Documents\Preprocess\SkewEstimator;
use App\Wishlist\Catalog\ImageRejected;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/** A white page with rows of dark word-like bars, like lines of text. */
function ocrPrePage(int $w, int $h, int $white = 255, int $ink = 0): GdImage
{
    $img = imagecreatetruecolor($w, $h);
    $bg = (int) imagecolorallocate($img, $white, $white, $white);
    $fg = (int) imagecolorallocate($img, $ink, $ink, $ink);
    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $bg);
    $row = max(8, intdiv($h, 50));
    $bar = max(2, intdiv($row, 3));
    for ($y = $row * 2; $y < $h - $row * 2; $y += $row) {
        for ($x = intdiv($w, 12); $x < $w - intdiv($w, 12); $x += intdiv($row * 3, 2)) {
            imagefilledrectangle($img, $x, $y, $x + intdiv($row * 5, 4), $y + $bar, $fg);
        }
    }

    return $img;
}

function ocrPreJpeg(GdImage $img, string $path): string
{
    imagejpeg($img, $path, 90);
    imagedestroy($img);

    return $path;
}

function ocrPreRotated(int $w, int $h, float $deg): GdImage
{
    $page = ocrPrePage($w, $h);
    $rot = imagerotate($page, $deg, (int) imagecolorallocate($page, 255, 255, 255));
    imagedestroy($page);
    // Crop back to the page size so the skewed page is the same size as the upright one.
    $crop = imagecrop($rot, ['x' => intdiv(imagesx($rot) - $w, 2), 'y' => intdiv(imagesy($rot) - $h, 2), 'width' => $w, 'height' => $h]);
    imagedestroy($rot);

    return $crop;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ocr-pre-'.strtolower((string) Str::ulid());
    mkdir($this->dir, 0700, true);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

it('fits a 3600 px page to 2048 px as a lossless PNG with the same aspect ratio', function () {
    $src = ocrPreJpeg(ocrPrePage(3600, 2545), $this->dir.'/in.jpg');
    $r = (new DocumentPreprocessor)->fit($src, $this->dir.'/out.png');
    [$w, $h, $type] = getimagesize($this->dir.'/out.png');

    expect($type)->toBe(IMAGETYPE_PNG)
        ->and(max($w, $h))->toBe(2048)
        ->and(abs($h - (int) round(2048 * 2545 / 3600)))->toBeLessThanOrEqual(1)
        ->and($r['ms'])->toBeInt()->and($r['peak_bytes'])->toBeGreaterThan(0);
});

it('leaves a small image at its size and keeps portrait pages portrait', function () {
    $src = ocrPreJpeg(ocrPrePage(1200, 800), $this->dir.'/small.jpg');
    (new DocumentPreprocessor)->fit($src, $this->dir.'/small.png');
    expect(array_slice(getimagesize($this->dir.'/small.png'), 0, 2))->toBe([1200, 800]);

    $tall = ocrPreJpeg(ocrPrePage(2545, 3600), $this->dir.'/tall.jpg');
    (new DocumentPreprocessor)->fit($tall, $this->dir.'/tall.png');
    [$w, $h] = getimagesize($this->dir.'/tall.png');
    expect($h)->toBe(2048)->and($w)->toBeLessThan($h);
});

it('stretches a low contrast page to the full luminance range', function () {
    $src = ocrPreJpeg(ocrPrePage(1600, 1100, 175, 110), $this->dir.'/low.jpg');
    (new DocumentPreprocessor)->autolevel($src, $this->dir.'/low.png');
    $img = imagecreatefrompng($this->dir.'/low.png');
    $min = 255;
    $max = 0;
    for ($y = 0; $y < imagesy($img); $y += 3) {
        for ($x = 0; $x < imagesx($img); $x += 3) {
            $v = imagecolorat($img, $x, $y) & 0xFF;
            $min = min($min, $v);
            $max = max($max, $v);
        }
    }
    expect($min)->toBeLessThanOrEqual(15)->and($max)->toBeGreaterThanOrEqual(240);
});

it('sharpens and smooths without changing the size', function () {
    $src = ocrPreJpeg(ocrPrePage(1500, 1000, 200, 60), $this->dir.'/s.jpg');
    (new DocumentPreprocessor)->denoiseSharpen($src, $this->dir.'/s.png');
    expect(array_slice(getimagesize($this->dir.'/s.png'), 0, 2))->toBe([1500, 1000]);
});

it('estimates the skew of a page rotated by 4 degrees and rotates it back', function () {
    $img = ocrPreRotated(1800, 1270, 4.0);
    $est = (new SkewEstimator)->estimate($img);
    expect($est)->toBeGreaterThan(3.5)->toBeLessThan(4.5);

    $src = ocrPreJpeg($img, $this->dir.'/skew.jpg');
    $r = (new DocumentPreprocessor)->deskew($src, $this->dir.'/skew.png');
    $out = imagecreatefrompng($this->dir.'/skew.png');
    expect($r['angle'])->toBeGreaterThan(3.5)->toBeLessThan(4.5)
        ->and(abs((new SkewEstimator)->estimate($out)))->toBeLessThan(0.8)
        ->and([imagesx($out), imagesy($out)])->toBe([1800, 1270]);
});

it('estimates the opposite direction too', function () {
    $est = (new SkewEstimator)->estimate(ocrPreRotated(1800, 1270, -6.0));
    expect($est)->toBeLessThan(-5.4)->toBeGreaterThan(-6.6);
});

it('leaves a page skewed by 0.3 degrees untouched', function () {
    $src = ocrPreJpeg(ocrPreRotated(1600, 1130, 0.3), $this->dir.'/flat.jpg');
    $r = (new DocumentPreprocessor)->deskew($src, $this->dir.'/flat.png');
    expect($r['angle'])->toBe(0.0);
});

it('runs the full chain on a 3600 px source inside 100 MB of PHP memory and 3 seconds', function (int $w, int $h) {
    $src = ocrPreJpeg(ocrPreRotated($w, $h, 2.0), $this->dir.'/big.jpg');
    $out = [];
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=128M '.escapeshellarg(base_path('tests/Support/preprocess-memory-probe.php')).' '
        .escapeshellarg($src).' '.escapeshellarg($this->dir.'/big.png').' 2>&1', $out, $code);
    $res = json_decode((string) end($out), true);

    expect($code)->toBe(0)->and($res)->toBeArray()
        ->and($res['peak'])->toBeLessThan(100 * 1024 * 1024)
        ->and($res['ms'])->toBeLessThan(3000);
    [$ow, $oh] = getimagesize($this->dir.'/big.png');
    expect(max($ow, $oh))->toBeLessThanOrEqual(2048);
})->with([[3600, 2545], [2545, 3600]]);

it('keeps every arm within 2048 px', function () {
    $src = ocrPreJpeg(ocrPreRotated(3000, 2120, 7.0), $this->dir.'/a.jpg');
    $p = new DocumentPreprocessor;
    $steps = ['fit' => fn ($d) => $p->fit($src, $d), 'autolevel' => fn ($d) => $p->autolevel($src, $d),
        'sharpen' => fn ($d) => $p->denoiseSharpen($src, $d), 'deskew' => fn ($d) => $p->deskew($src, $d),
        'chain' => fn ($d) => $p->chain($src, $d, DocumentPreprocessor::STEPS)];
    foreach ($steps as $name => $run) {
        $run($this->dir.'/'.$name.'.png');
        [$w, $h] = getimagesize($this->dir.'/'.$name.'.png');
        expect(max($w, $h))->toBeLessThanOrEqual(2048, $name);
    }
});

it('refuses an unknown step, an unsupported file and a source over the decode budget', function () {
    $src = ocrPreJpeg(ocrPrePage(800, 600), $this->dir.'/x.jpg');
    $p = new DocumentPreprocessor;
    expect(fn () => $p->chain($src, $this->dir.'/x.png', ['binarise']))->toThrow(InvalidArgumentException::class);

    file_put_contents($this->dir.'/not-image.jpg', 'plain text');
    expect(fn () => $p->fit($this->dir.'/not-image.jpg', $this->dir.'/y.png'))->toThrow(ImageRejected::class);

    config(['medical.image_decode_budget_bytes' => 1_000_000]);
    expect(fn () => $p->fit($src, $this->dir.'/z.png'))->toThrow(ImageRejected::class);
});

it('ships ground-truth texts that are NFC, fictional and cover the diacritics', function () {
    $dir = base_path('tests/Fixtures/ocr-eval');
    $corpus = json_decode((string) file_get_contents($dir.'/corpus.json'), true);
    expect($corpus['docs'])->toHaveCount(6);

    $all = '';
    foreach ($corpus['docs'] as $d) {
        $txt = (string) file_get_contents($dir.'/'.$d['id'].'.txt');
        $all .= $txt;
        expect(mb_check_encoding($txt, 'UTF-8'))->toBeTrue()
            ->and(Normalizer::isNormalized($txt, Normalizer::FORM_C))->toBeTrue()
            ->and($txt)->toContain('(demo)')
            ->and($txt)->not->toContain("\r");

        $expected = (string) file_get_contents($dir.'/'.$d['id'].'.expected.json');
        expect((new ExtractionValidator)->validate($expected)->ok)->toBeTrue($d['id']);
    }
    $probe = (string) file_get_contents($dir.'/probe.txt');
    expect(Normalizer::isNormalized($probe, Normalizer::FORM_C))->toBeTrue();

    foreach (mb_str_split('ľščťžýôňĺŕäąćęłńóśźżŽŁ') as $c) {
        expect($all)->toContain($c);
    }
});
