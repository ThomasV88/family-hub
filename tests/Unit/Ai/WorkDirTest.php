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
