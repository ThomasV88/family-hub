<?php

use App\Medical\Documents\PdfRasterizer;
use Illuminate\Support\Str;
use Tests\Support\MedicalFileFixtures;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/rast-test-'.strtolower((string) Str::ulid());
    mkdir($this->dir, 0700, true);
    $this->pdf = function (int $n) {
        $f = $this->dir.'/doc'.$n.'.pdf';
        file_put_contents($f, MedicalFileFixtures::pdfPages($n));

        return $f;
    };
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        is_dir($f) ? array_map('unlink', glob($f.'/*') ?: []) + [@rmdir($f)] : @unlink($f);
    }
    @rmdir($this->dir);
});

it('counts pages and returns 0 for junk or truncated files', function () {
    $r = new PdfRasterizer;
    expect($r->pageCount(($this->pdf)(3)))->toBe(3);

    file_put_contents($this->dir.'/junk.pdf', 'hello not a pdf');
    expect($r->pageCount($this->dir.'/junk.pdf'))->toBe(0);

    file_put_contents($this->dir.'/trunc.pdf', substr(MedicalFileFixtures::pdfPages(3), 0, 60));
    expect($r->pageCount($this->dir.'/trunc.pdf'))->toBe(0);
    expect($r->pageCount($this->dir.'/missing.pdf'))->toBe(0);
});

it('renders one page as a bounded jpeg and null out of range', function () {
    $r = new PdfRasterizer;
    $pdf = ($this->pdf)(3);

    $bytes = $r->page($pdf, 2);
    expect($bytes)->not->toBeNull()
        ->and(substr($bytes, 0, 3))->toBe("\xFF\xD8\xFF");
    $img = imagecreatefromstring($bytes);
    expect($img)->not->toBeFalse()
        ->and(max(imagesx($img), imagesy($img)))->toBeLessThanOrEqual(1800);

    expect($r->page($pdf, 4))->toBeNull()
        ->and($r->page($pdf, 0))->toBeNull();
});

it('writes only the first N pages into the directory', function () {
    $r = new PdfRasterizer;
    $out = $this->dir.'/out';
    mkdir($out, 0700);

    $res = $r->pages(($this->pdf)(12), $out, 10);

    expect($res['total'])->toBe(12)->and($res['written'])->toHaveCount(10);
    $names = array_map('basename', glob($out.'/*'));
    sort($names);
    $expected = array_map(fn ($i) => "page-$i.jpg", range(1, 10));
    sort($expected);
    expect($names)->toBe($expected);
});

it('returns nothing and leaves no process when the timeout is hit', function () {
    config(['ai.pdf.timeout' => 0.001, 'ai.pdf.info_timeout' => 0.001]);
    $r = new PdfRasterizer;
    $pdf = ($this->pdf)(2);

    expect($r->pageCount($pdf))->toBe(0)
        ->and($r->page($pdf, 1))->toBeNull();
    config(['ai.pdf.info_timeout' => 5]);
    expect($r->pages($pdf, $this->dir, 2)['written'])->toBe([]);

    usleep(200_000);
    exec('pgrep -x pdftoppm', $o, $code);
    expect($code)->toBe(1);
});

it('never writes under the medical disk root', function () {
    $root = (string) config('filesystems.disks.medical.root');
    $before = is_dir($root) ? (scandir($root) ?: []) : null;

    $r = new PdfRasterizer;
    $pdf = ($this->pdf)(2);
    $r->page($pdf, 1);
    $r->pages($pdf, $this->dir, 2);

    expect(is_dir($root) ? scandir($root) : null)->toBe($before);
});

it('keeps the memory limits below the container limits and gives the worker its own', function () {
    // compose.server.yaml: app 192m, ai-worker 384m.
    expect((int) config('ai.pdf.ulimit_kb'))->toBeLessThan(192 * 1024)
        ->and((int) config('ai.pdf.ulimit_kb_worker'))->toBeLessThan(384 * 1024)
        ->and((int) config('ai.pdf.ulimit_kb_worker'))->toBeGreaterThan((int) config('ai.pdf.ulimit_kb'));
});

it('renders a page with the web limit and the worker pages with the worker limit', function () {
    $r = new PdfRasterizer;
    $pdf = ($this->pdf)(2);
    $out = $this->dir.'/out2';
    mkdir($out, 0700);

    expect($r->page($pdf, 1))->not->toBeNull()
        ->and($r->pages($pdf, $out, 10)['written'])->toHaveCount(2);
});

it('answers a page past the end with null from a single pdftoppm spawn, without pdfinfo', function () {
    $r = new PdfRasterizer;
    $pdf = ($this->pdf)(2);
    // A pdfinfo that would time out cannot slow the page route down: it is never run.
    config(['ai.pdf.info_timeout' => 0.000001]);

    expect($r->page($pdf, 3))->toBeNull()->and($r->page($pdf, 2))->not->toBeNull();
});
