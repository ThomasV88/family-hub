<?php

use App\Medical\Documents\Adjustments;
use App\Medical\Documents\AiCopyAdjuster;

function ubAdjDir(): string
{
    $dir = sys_get_temp_dir().'/ub-adj-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

/** A w x h image split in a left patch of $a and a right patch of $b (grey levels), as .jpg or .png. */
function ubAdjImage(string $dir, int $w, int $h, int $a, int $b, string $ext = 'jpg'): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($img, $a, $a, $a));
    imagefilledrectangle($img, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($img, $b, $b, $b));
    $path = $dir.'/page.'.$ext;
    $ext === 'png' ? imagepng($img, $path) : imagejpeg($img, $path, 100);
    imagedestroy($img);

    return $path;
}

/** @return array{0: int, 1: int, 2: int} rgb at the given point */
function ubAdjPixel(string $path, int $x, int $y): array
{
    $img = imagecreatefromstring(file_get_contents($path));
    $c = imagecolorat($img, $x, $y);
    imagedestroy($img);

    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}

beforeEach(function () {
    $this->dirs = [];
});

afterEach(function () {
    foreach ($this->dirs as $d) {
        array_map('unlink', glob($d.'/*') ?: []);
        @rmdir($d);
    }
});

it('clamps, defaults and reports neutral', function () {
    expect(Adjustments::fromArray(['brightness' => 999, 'contrast' => 1, 'bw' => 1])->toArray())->toBe(['brightness' => 150, 'contrast' => 50, 'bw' => true])
        ->and(Adjustments::fromArray(['brightness' => 'x', 'contrast' => null])->toArray())->toBe(['brightness' => 100, 'contrast' => 100, 'bw' => false])
        ->and(Adjustments::fromArray(null)->isNeutral())->toBeTrue()
        ->and(Adjustments::neutral()->isNeutral())->toBeTrue()
        ->and((new Adjustments(100, 100, false))->isNeutral())->toBeTrue()
        ->and((new Adjustments(101, 100, false))->isNeutral())->toBeFalse()
        ->and((new Adjustments(100, 100, true))->isNeutral())->toBeFalse()
        ->and(array_keys(Adjustments::neutral()->toArray()))->toBe(['brightness', 'contrast', 'bw']);
});

it('raises and lowers brightness', function () {
    $dir = ubAdjDir();
    $this->dirs[] = $dir;
    $p = ubAdjImage($dir, 40, 40, 128, 128);
    expect((new AiCopyAdjuster)->apply($p, new Adjustments(150, 100, false)))->toBeTrue();
    expect(ubAdjPixel($p, 5, 5)[0])->toBeGreaterThan(128);

    $p = ubAdjImage($dir, 40, 40, 128, 128);
    expect((new AiCopyAdjuster)->apply($p, new Adjustments(50, 100, false)))->toBeTrue();
    expect(ubAdjPixel($p, 5, 5)[0])->toBeLessThan(128);
});

it('widens and narrows contrast', function () {
    $dir = ubAdjDir();
    $this->dirs[] = $dir;
    $p = ubAdjImage($dir, 40, 40, 100, 160);
    (new AiCopyAdjuster)->apply($p, new Adjustments(100, 200, false));
    expect(ubAdjPixel($p, 35, 5)[0] - ubAdjPixel($p, 5, 5)[0])->toBeGreaterThan(70);

    $p = ubAdjImage($dir, 40, 40, 100, 160);
    (new AiCopyAdjuster)->apply($p, new Adjustments(100, 50, false));
    expect(ubAdjPixel($p, 35, 5)[0] - ubAdjPixel($p, 5, 5)[0])->toBeLessThan(50);
});

it('makes black and white with equal channels', function () {
    $dir = ubAdjDir();
    $this->dirs[] = $dir;
    $img = imagecreatetruecolor(20, 20);
    imagefilledrectangle($img, 0, 0, 19, 19, imagecolorallocate($img, 200, 40, 90));
    $p = $dir.'/page.png';
    imagepng($img, $p);
    imagedestroy($img);

    expect((new AiCopyAdjuster)->apply($p, new Adjustments(100, 100, true)))->toBeTrue();
    [$r, $g, $b] = ubAdjPixel($p, 3, 3);
    expect(abs($r - $g))->toBeLessThanOrEqual(1)->and(abs($g - $b))->toBeLessThanOrEqual(1);
});

it('fits to 2048 px and keeps small images, name and format', function () {
    $dir = ubAdjDir();
    $this->dirs[] = $dir;
    $big = ubAdjImage($dir, 4000, 3000, 128, 128);
    expect((new AiCopyAdjuster)->apply($big, new Adjustments(120, 100, false)))->toBeTrue();
    $info = getimagesize($big);
    expect(max($info[0], $info[1]))->toBe(2048)->and($info[2])->toBe(IMAGETYPE_JPEG);

    $small = ubAdjImage($dir, 1000, 500, 128, 128, 'png');
    expect((new AiCopyAdjuster)->apply($small, new Adjustments(120, 100, false)))->toBeTrue();
    $info = getimagesize($small);
    expect([$info[0], $info[1], $info[2]])->toBe([1000, 500, IMAGETYPE_PNG])
        ->and(basename($small))->toBe('page.png')
        ->and(glob($dir.'/*'))->toHaveCount(2);
});

it('refuses an oversized or non-image input and leaves no partial output', function () {
    $dir = ubAdjDir();
    $this->dirs[] = $dir;
    $junk = $dir.'/junk.jpg';
    file_put_contents($junk, 'not an image');
    expect((new AiCopyAdjuster)->apply($junk, new Adjustments(120, 100, false)))->toBeFalse()
        ->and(file_get_contents($junk))->toBe('not an image');

    $p = ubAdjImage($dir, 400, 400, 128, 128);
    $before = file_get_contents($p);
    config(['medical.image_decode_budget_bytes' => 1000]);
    expect((new AiCopyAdjuster)->apply($p, new Adjustments(120, 100, false)))->toBeFalse()
        ->and(file_get_contents($p))->toBe($before)
        ->and(glob($dir.'/*'))->toHaveCount(2);
});

it('follows the CSS filter maths for grey 128', function (int $bright, int $contrast) {
    $dir = ubAdjDir();
    $this->dirs[] = $dir;
    $p = ubAdjImage($dir, 20, 20, 128, 128, 'png');
    (new AiCopyAdjuster)->apply($p, new Adjustments($bright, $contrast, false));
    $b = $bright / 100;
    $k = $contrast / 100;
    $expected = (int) max(0, min(255, round((128 * $b - 127.5) * $k + 127.5)));
    expect(abs(ubAdjPixel($p, 3, 3)[0] - $expected))->toBeLessThanOrEqual(2);
})->with([[130, 150], [70, 180], [100, 200], [150, 50], [60, 100]]);
