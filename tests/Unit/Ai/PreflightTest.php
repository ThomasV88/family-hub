<?php

use App\Medical\Ai\Preflight;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/pf-test-'.strtolower((string) Str::ulid());
    mkdir($this->base, 0700, true);
    $this->scripts = [];
    // A "good" home/work dir outside the system temp dir cannot be assumed writable, so use the project storage dir.
    $this->home = storage_path('framework/testing/pf-'.strtolower((string) Str::ulid()));
    mkdir($this->home.'/home', 0700, true);
    mkdir($this->home.'/work', 0700, true);
    $this->good = function (string $version = 'codex-cli 0.169.0') {
        $bin = FakeCodex::loginStatus(0, $version);
        $this->scripts[] = $bin;
        config([
            'ai.codex.binary' => $bin,
            'ai.codex.home' => $this->home.'/home',
            'ai.codex.version' => '0.169.0',
            'ai.work_dir' => $this->home.'/work',
            'app.key' => '',
            'queue.connections.database.retry_after' => 900,
        ]);

        return $bin;
    };
    $this->saved = [];
    foreach (config('ai.forbidden_env') as $n) {
        $this->saved[$n] = getenv($n);
        putenv($n);
        unset($_ENV[$n], $_SERVER[$n]);
    }
});

afterEach(function () {
    foreach ($this->saved as $n => $v) {
        $v === false ? putenv($n) : putenv("$n=$v");
    }
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
    foreach ([$this->home.'/home', $this->home.'/work', $this->home] as $d) {
        @rmdir($d);
    }
    @rmdir($this->base);
});

it('is clean when everything is satisfied', function () {
    ($this->good)();
    expect((new Preflight)->problems())->toBe([])
        ->and((new Preflight)->envKeysClean())->toBeTrue();
});

it('flags every forbidden variable', function () {
    ($this->good)();
    foreach (config('ai.forbidden_env') as $n) {
        putenv("$n=canary");
        expect((new Preflight)->envKeysClean())->toBeFalse()
            ->and((new Preflight)->problems())->toContain('forbidden_env');
        putenv($n);
        expect((new Preflight)->envKeysClean())->toBeTrue();
    }
});

it('flags a present app key only', function () {
    ($this->good)();
    config(['app.key' => 'base64:abc']);
    expect((new Preflight)->problems())->toBe(['app_key_present']);
});

it('checks the binary and its pinned version', function () {
    ($this->good)('codex-cli 0.170.0');
    expect((new Preflight)->problems())->toBe(['version_mismatch']);

    config(['ai.codex.binary' => $this->base.'/nope']);
    expect((new Preflight)->problems())->toBe(['binary_missing']);
});

it('rejects a codex home in tmp and an unusable one', function () {
    ($this->good)();
    config(['ai.codex.home' => $this->base]);
    expect((new Preflight)->problems())->toBe(['codex_home_in_tmp']);

    config(['ai.codex.home' => $this->home.'/missing']);
    expect((new Preflight)->problems())->toBe(['codex_home_unwritable']);
});

it('requires retry_after above the job timeout plus margin', function () {
    ($this->good)();
    config(['queue.connections.database.retry_after' => 90]);
    expect((new Preflight)->problems())->toBe(['retry_after_too_small']);
    config(['queue.connections.database.retry_after' => 900]);
    expect((new Preflight)->problems())->toBe([]);
});

it('flags an unwritable work dir', function () {
    ($this->good)();
    config(['ai.work_dir' => $this->home.'/nowhere']);
    expect((new Preflight)->problems())->toBe(['work_dir_unwritable']);
});

it('prints codes only and never a variable value', function () {
    ($this->good)();
    putenv('OPENAI_API_KEY=CANARY-sk-123456');

    $code = Artisan::call('ai:preflight');
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('forbidden_env')
        ->and($output)->not->toContain('CANARY')
        ->and($output)->not->toContain('sk-123456');

    putenv('OPENAI_API_KEY');
    expect(Artisan::call('ai:preflight'))->toBe(0)
        ->and(Artisan::output())->toContain('AI preflight OK');
});
