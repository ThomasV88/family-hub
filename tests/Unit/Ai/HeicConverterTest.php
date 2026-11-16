<?php

use App\Medical\Documents\HeicConverter;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/heic-test-'.strtolower((string) Str::ulid());
    mkdir($this->dir, 0700, true);
    $this->heic = $this->dir.'/in with space;$(x).heic';
    file_put_contents($this->heic, 'ignored by the fake');
    $this->out = $this->dir.'/out.jpg';
    $this->fake = function (string $body): string {
        $bin = $this->dir.'/fake-dec';
        file_put_contents($bin, "#!/bin/sh\n".$body."\n");
        chmod($bin, 0700);
        config(['ai.heic.binary' => $bin]);

        return $bin;
    };
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

it('passes the decoder an argv with the input and one output path only, without shell interpolation', function () {
    $jpeg = $this->dir.'/src.jpg';
    file_put_contents($jpeg, ImageFixtures::jpeg(64, 48));
    $argvLog = $this->dir.'/argv';
    ($this->fake)('printf "%s\n" "$#" "$1" >'.escapeshellarg($argvLog).'; cp '.escapeshellarg($jpeg).' "$2"');

    $ok = (new HeicConverter)->toJpeg($this->heic, $this->out);

    $lines = explode("\n", trim((string) file_get_contents($argvLog)));
    expect($ok)->toBeTrue()
        ->and($lines[0])->toBe('2')
        ->and($lines[1])->toBe($this->heic)
        ->and(substr((string) file_get_contents($this->out), 0, 3))->toBe("\xFF\xD8\xFF");
});

it('strips EXIF and GPS from the decoder output', function () {
    $jpeg = $this->dir.'/src.jpg';
    file_put_contents($jpeg, ImageFixtures::jpegWithExif(1, 64, 48));
    expect(file_get_contents($jpeg))->toContain('Exif');
    ($this->fake)('cp '.escapeshellarg($jpeg).' "$2"');

    expect((new HeicConverter)->toJpeg($this->heic, $this->out))->toBeTrue()
        ->and(file_get_contents($this->out))->not->toContain('Exif');
});

it('gives up quickly on a hanging decoder and on failures, writing nothing', function (string $body) {
    config(['ai.heic.timeout' => 0.5]);
    ($this->fake)($body);

    $t = microtime(true);
    expect((new HeicConverter)->toJpeg($this->heic, $this->out))->toBeFalse()
        ->and(file_exists($this->out))->toBeFalse()
        ->and(microtime(true) - $t)->toBeLessThan(5);
})->with([
    'hang' => ['while :; do sleep 1; done'],
    'exit 1' => ['exit 1'],
    'no file' => ['exit 0'],
]);

it('leaves no scratch directory behind', function () {
    ($this->fake)('exit 1');
    $before = glob(sys_get_temp_dir().'/fh-*') ?: [];

    (new HeicConverter)->toJpeg($this->heic, $this->out);

    expect(glob(sys_get_temp_dir().'/fh-*') ?: [])->toBe($before);
});

it('decodes a real HEIC with the installed decoder into a plain JPEG', function () {
    exec('command -v '.escapeshellarg((string) config('ai.heic.binary')).' 2>/dev/null', $o, $code);
    if ($code !== 0) {
        $this->markTestSkipped('heif-dec is not installed in this image (it lives in the ai image).');
    }
    $in = $this->dir.'/real.heic';
    file_put_contents($in, MedicalFileFixtures::realHeic());

    expect((new HeicConverter)->toJpeg($in, $this->out))->toBeTrue();
    $bytes = (string) file_get_contents($this->out);
    $size = getimagesizefromstring($bytes);
    expect(substr($bytes, 0, 3))->toBe("\xFF\xD8\xFF")
        ->and($size[0])->toBe(64)->and($size[1])->toBe(48)
        ->and($bytes)->not->toContain('Exif');
});
