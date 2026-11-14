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
    $this->copVersion = null;
    // A "good" home/work dir outside the system temp dir cannot be assumed writable, so use the project storage dir.
    $this->home = storage_path('framework/testing/pf-'.strtolower((string) Str::ulid()));
    mkdir($this->home.'/home', 0700, true);
    mkdir($this->home.'/work', 0700, true);
    mkdir($this->home.'/cphome', 0700, true);
    mkdir($this->home.'/cache/pkg/universal/1.0.112', 0700, true);
    touch($this->home.'/cache/pkg/universal/1.0.112/.extraction-complete');
    $this->good = function (string $version = 'codex-cli 0.169.0') {
        $bin = FakeCodex::loginStatus(0, $version);
        $this->scripts[] = $bin;
        $cop = tempnam(sys_get_temp_dir(), 'fakecop');
        file_put_contents($cop, "#!/bin/sh\necho 'GitHub Copilot CLI ".($this->copVersion ?? '1.0.112.')."'\n");
        chmod($cop, 0700);
        $this->scripts[] = $cop;
        config([
            'ai.copilot.binary' => $cop,
            'ai.copilot.home' => $this->home.'/cphome',
            'ai.copilot.cache' => $this->home.'/cache',
            'ai.copilot.version' => '1.0.112',
            'ai.copilot.model' => null,
            'ai.codex.binary' => $bin,
            'ai.codex.home' => $this->home.'/home',
            'ai.codex.version' => '0.169.0',
            'ai.work_dir' => $this->home.'/work',
            'app.key' => '',
            'queue.connections.database.retry_after' => 900,
        ]);

        return $bin;
    };
    // The dev and test containers carry mail and cloud variables; the ai-worker never does. Clear them for the test.
    $this->secrets = [];
    foreach (array_unique(array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER))) as $n) {
        foreach (config('ai.forbidden_env_patterns') as $pattern) {
            if (preg_match($pattern, (string) $n) === 1) {
                $this->secrets[$n] = [getenv($n), $_ENV[$n] ?? null, $_SERVER[$n] ?? null];
                putenv((string) $n);
                unset($_ENV[$n], $_SERVER[$n]);
            }
        }
    }
    $this->saved = [];
    foreach (config('ai.forbidden_env') as $n) {
        $this->saved[$n] = getenv($n);
        putenv($n);
        unset($_ENV[$n], $_SERVER[$n]);
    }
});

afterEach(function () {
    foreach ($this->secrets as $n => [$env, $e, $srv]) {
        $env === false ? putenv((string) $n) : putenv("$n=$env");
        $e === null ? null : $_ENV[$n] = $e;
        $srv === null ? null : $_SERVER[$n] = $srv;
    }
    foreach ($this->saved as $n => $v) {
        $v === false ? putenv($n) : putenv("$n=$v");
    }
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
    @unlink($this->home.'/cache/pkg/universal/1.0.112/.extraction-complete');
    foreach ([$this->home.'/cache/pkg/universal/1.0.112', $this->home.'/cache/pkg/universal', $this->home.'/cache/pkg', $this->home.'/cache', $this->home.'/cphome', $this->home.'/home', $this->home.'/work', $this->home] as $d) {
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

it('flags mail, Google, VAPID, cloud and key or secret variables as forbidden_secret, by name and without a value', function (string $name) {
    ($this->good)();
    putenv("$name=CANARY-value-123");

    expect((new Preflight)->secretsClean())->toBeFalse()
        ->and((new Preflight)->problems())->toBe(['forbidden_secret']);
    Artisan::call('ai:preflight');
    expect(Artisan::output())->toContain('forbidden_secret')->not->toContain('CANARY')->not->toContain($name);

    putenv($name);
    expect((new Preflight)->secretsClean())->toBeTrue()->and((new Preflight)->problems())->toBe([]);
})->with(['MAIL_PASSWORD', 'MAIL_HOST', 'GOOGLE_CLIENT_SECRET', 'VAPID_PRIVATE_KEY', 'AWS_ACCESS_KEY_ID', 'SOME_VENDOR_API_KEY', 'WEBHOOK_SECRET']);

it('ignores an empty forbidden-looking variable', function () {
    ($this->good)();
    putenv('MAIL_PASSWORD=');

    expect((new Preflight)->secretsClean())->toBeTrue();
    putenv('MAIL_PASSWORD');
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

it('flags an empty or unsafe work dir', function (string $dir) {
    ($this->good)();
    config(['ai.work_dir' => $dir]);

    expect((new Preflight)->problems())->toBe(['work_dir_unwritable']);
})->with(['', '/', 'relative']);

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

it('flags a configured model that is not a plain identifier', function (string $model, bool $bad) {
    ($this->good)();
    config(['ai.codex.model' => $model]);

    expect((new Preflight)->problems())->toBe($bad ? ['model_invalid'] : []);
})->with([['gpt-5.1-codex', false], ['', false], ['--config', true], ['a=b', true], ['-x', true]]);

it('refuses every GitHub and Copilot token or BYOK variable, but not an empty value or GH_HOST', function (string $name) {
    ($this->good)();
    foreach ([fn () => putenv("$name=canary"), function () use ($name) {
        $_ENV[$name] = 'canary';
    }, function () use ($name) {
        $_SERVER[$name] = 'canary';
    }] as $set) {
        $set();
        expect((new Preflight)->envKeysClean())->toBeFalse()->and((new Preflight)->problems())->toContain('forbidden_env');
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
    putenv("$name=");
    expect((new Preflight)->envKeysClean())->toBeTrue();
    putenv($name);
})->with(['GH_TOKEN', 'GITHUB_TOKEN', 'COPILOT_GITHUB_TOKEN', 'COPILOT_ALLOW_ALL', 'COPILOT_PROVIDER_BASE_URL', 'COPILOT_PROVIDER_API_KEY',
    'COPILOT_PROVIDER_API_KEY_COMMAND', 'COPILOT_PROVIDER_BEARER_TOKEN', 'COPILOT_OFFLINE']);

it('does not treat GH_HOST as forbidden', function () {
    ($this->good)();
    putenv('GH_HOST=github.example');
    expect((new Preflight)->envKeysClean())->toBeTrue();
    putenv('GH_HOST');
});

it('checks the Copilot binary and pinned version, tolerating a trailing dot', function () {
    ($this->good)();
    expect((new Preflight)->problems())->toBe([]);

    $this->copVersion = '1.0.111';
    ($this->good)();
    expect((new Preflight)->problems())->toBe(['copilot_version_mismatch']);

    config(['ai.copilot.binary' => $this->base.'/nope']);
    expect((new Preflight)->problems())->toBe(['copilot_binary_missing']);
});

it('requires the baked Copilot cache marker', function () {
    ($this->good)();
    unlink($this->home.'/cache/pkg/universal/1.0.112/.extraction-complete');
    expect((new Preflight)->problems())->toBe(['copilot_cache_missing']);
    touch($this->home.'/cache/pkg/universal/1.0.112/.extraction-complete');
});

it('rejects a Copilot home that is unwritable or in tmp', function () {
    ($this->good)();
    config(['ai.copilot.home' => $this->home.'/missing']);
    expect((new Preflight)->problems())->toBe(['copilot_home_unwritable']);

    config(['ai.copilot.home' => $this->base]);
    expect((new Preflight)->problems())->toBe(['copilot_home_in_tmp']);
});

it('flags an invalid Copilot fallback model only', function (string $model, bool $bad) {
    ($this->good)();
    config(['ai.copilot.model' => $model]);
    expect((new Preflight)->problems())->toBe($bad ? ['copilot_model_invalid'] : []);
})->with([['-x', true], ['a=b', true], ['gpt-5', false], ['', false]]);
