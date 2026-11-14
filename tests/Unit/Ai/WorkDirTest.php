<?php

use App\Medical\Ai\Work\WorkDir;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/wd-test-'.strtolower((string) Str::ulid());
    mkdir($this->root, 0700, true);
    config(['ai.work_dir' => $this->root]);
});

afterEach(function () {
    WorkDir::removeTree($this->root);
});

it('creates a private dir with tmp and accepts only app-style names', function () {
    $w = WorkDir::create();

    expect(is_dir($w->path().'/tmp'))->toBeTrue()
        ->and(decoct(fileperms($w->path()) & 0777))->toBe('700')
        ->and(decoct(fileperms($w->path().'/tmp') & 0777))->toBe('700');

    $w->put('page-1.jpg', 'x');
    expect(file_get_contents($w->path().'/page-1.jpg'))->toBe('x');

    expect(fn () => $w->put('../x', 'y'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $w->put('A B', 'y'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $w->put('..', 'y'))->toThrow(InvalidArgumentException::class);
});

it('cleans up recursively and idempotently', function () {
    $w = WorkDir::create();
    $w->put('a.txt', 'x');
    file_put_contents($w->path().'/tmp/inner', 'y');

    $w->cleanup();
    expect(file_exists($w->path()))->toBeFalse();
    $w->cleanup();
    expect(file_exists($w->path()))->toBeFalse();
});

it('sweeps only stale dirs', function () {
    $old = WorkDir::create();
    $new = WorkDir::create();
    touch($old->path(), time() - 3600);

    expect(WorkDir::sweepStale(30))->toBe(1)
        ->and(file_exists($old->path()))->toBeFalse()
        ->and(file_exists($new->path()))->toBeTrue();
});

it('refuses to sweep or create under an empty, relative, root, dotted or Codex-parent work dir', function (string $value) {
    $victim = $this->root.'/victim';
    mkdir($victim, 0700);
    touch($victim, time() - 7200);
    config(['ai.work_dir' => $value, 'ai.codex.home' => '/codex']);

    expect(WorkDir::root())->toBeNull()
        ->and(WorkDir::sweepStale(30))->toBe(0)
        ->and(fn () => WorkDir::create())->toThrow(RuntimeException::class)
        ->and(is_dir($victim))->toBeTrue();
})->with(['', '   ', '/', '//', 'relative/dir', './x', '/tmp/../etc', '/tmp/x/..', '/codex']);

it('refuses a work dir that is a parent of the Codex home', function () {
    config(['ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'/login']);

    expect(WorkDir::root())->toBeNull()->and(WorkDir::sweepStale(0))->toBe(0);
});

it('sweeps only entries named like a job dir and never follows a link', function () {
    $job = WorkDir::create();
    touch($job->path(), time() - 7200);
    $other = $this->root.'/not-a-job-dir';
    mkdir($other, 0700);
    touch($other, time() - 7200);
    $target = sys_get_temp_dir().'/wd-target-'.strtolower((string) Str::ulid());
    mkdir($target, 0700);
    $link = $this->root.'/'.strtolower((string) Str::ulid());
    symlink($target, $link);
    touch($target, time() - 7200);

    expect(WorkDir::sweepStale(30))->toBe(1)
        ->and(file_exists($job->path()))->toBeFalse()
        ->and(is_dir($other))->toBeTrue()
        ->and(is_dir($target))->toBeTrue();
    @unlink($link);
    WorkDir::removeTree($target);
});

it('treats a work dir under a hidden-ish name or a missing dir as nothing to sweep', function () {
    config(['ai.work_dir' => $this->root.'/missing']);

    expect(WorkDir::sweepStale(0))->toBe(0);
});
