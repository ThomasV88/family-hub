<?php

use App\Medical\Ai\CodexRequest;
use App\Medical\Ai\CodexRunner;
use App\Medical\Ai\LoginState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->work = sys_get_temp_dir().'/ai-test-'.strtolower((string) Str::ulid());
    mkdir($this->work, 0700, true);
    $this->scripts = [];
    $this->fake = function (string $path) {
        $this->scripts[] = $path;
        config(['ai.codex.binary' => $path, 'ai.codex.home' => $this->work]);

        return $path;
    };
    $this->req = fn (?string $model = null, array $images = []) => new CodexRequest(
        $this->work, $this->work.'/schema.json', $this->work.'/result.json', $images, 'PROMPT TEXT', $model,
    );
});

afterEach(function () {
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
    exec('rm -rf '.escapeshellarg($this->work));
});

it('records the exact argv, scrubbed env, cwd and stdin', function () {
    $rec = $this->work.'-rec.txt';
    $this->scripts[] = $rec;
    ($this->fake)(FakeCodex::recording($rec, '{"ok":true}'));
    touch($this->work.'/a.jpg');
    touch($this->work.'/b.jpg');
    putenv('OPENAI_API_KEY=sk-secret');
    putenv('APP_KEY=base64:secret');
    putenv('DB_PASSWORD=pw');

    try {
        $res = (new CodexRunner)->exec(($this->req)(null, [$this->work.'/a.jpg', $this->work.'/b.jpg']));
    } finally {
        putenv('OPENAI_API_KEY');
        putenv('APP_KEY');
        putenv('DB_PASSWORD');
    }

    expect($res->exitCode)->toBe(0)
        ->and($res->timedOut || $res->idleTimedOut || $res->aborted || $res->outputCapped || $res->authSignature)->toBeFalse()
        ->and(file_get_contents($this->work.'/result.json'))->toBe('{"ok":true}');

    [$argv, $rest] = explode("---ENV---\n", (string) file_get_contents($rec), 2);
    [$env, $rest] = explode("---PWD---\n", $rest, 2);
    [$pwd, $rest] = explode("---LS---\n", $rest, 2);
    [$ls, $stdin] = explode("---STDIN---\n", $rest, 2);

    expect(explode("\n", trim($argv)))->toBe([
        'exec', '--sandbox', 'read-only', '--ephemeral', '--skip-git-repo-check', '--ignore-user-config',
        '--ignore-rules', '--color', 'never', '--json', '--output-schema', $this->work.'/schema.json',
        '-o', $this->work.'/result.json',
        '-c', 'web_search="disabled"', '-c', 'features.shell_tool=false', '-c', 'features.apps=false',
        '-c', 'forced_login_method="chatgpt"', '-c', 'history.persistence="none"', '-c', 'analytics.enabled=false',
        '-c', 'check_for_update_on_startup=false', '--disable', 'memories',
        '-i', $this->work.'/a.jpg,'.$this->work.'/b.jpg',
    ]);

    $keys = array_map(fn ($l) => explode('=', $l, 2)[0], array_filter(explode("\n", trim($env))));
    sort($keys);
    $allowed = ['CODEX_HOME', 'HOME', 'LANG', 'PATH', 'PWD', 'SHLVL', 'TMPDIR', 'TZ', '_', 'OLDPWD'];
    expect(array_diff($keys, $allowed))->toBe([])
        ->and($env)->not->toContain('sk-secret')->not->toContain('DB_PASSWORD')->not->toContain('APP_KEY')
        ->and($env)->toContain('TMPDIR='.$this->work.'/tmp')->toContain('TZ=UTC')
        ->and(trim($pwd))->toBe(realpath($this->work))
        ->and(explode("\n", trim($ls)))->toContain('a.jpg', 'b.jpg', 'tmp')
        ->and($stdin)->toBe('PROMPT TEXT');
});

it('adds -m only when a model is given', function () {
    $rec = $this->work.'-rec.txt';
    $this->scripts[] = $rec;
    ($this->fake)(FakeCodex::recording($rec));
    (new CodexRunner)->exec(($this->req)(null));
    expect(file_get_contents($rec))->not->toContain("\n-m\n");

    (new CodexRunner)->exec(($this->req)('gpt-x'));
    expect(file_get_contents($rec))->toContain("\n-m\ngpt-x\n");
});

it('times out a hanging run', function () {
    config(['ai.timeouts.process' => 1]);
    ($this->fake)(FakeCodex::hanging());
    $t = microtime(true);
    $res = (new CodexRunner)->exec(($this->req)());
    expect($res->timedOut)->toBeTrue()->and($res->idleTimedOut)->toBeFalse()
        ->and(microtime(true) - $t)->toBeLessThan(4.0);
});

it('idle-times-out a silent run', function () {
    config(['ai.timeouts.idle' => 1, 'ai.timeouts.process' => 30]);
    ($this->fake)(FakeCodex::silent());
    $res = (new CodexRunner)->exec(($this->req)());
    expect($res->idleTimedOut)->toBeTrue()->and($res->timedOut)->toBeFalse();
});

it('kills grandchildren with the group', function () {
    config(['ai.timeouts.process' => 1]);
    $pidFile = $this->work.'/child.pid';
    ($this->fake)(FakeCodex::forking($pidFile));
    $res = (new CodexRunner)->exec(($this->req)());
    expect($res->timedOut)->toBeTrue();
    $pid = (int) trim((string) file_get_contents($pidFile));
    expect($pid)->toBeGreaterThan(1);
    usleep(200_000);
    // A killed orphan may linger as a zombie when the container's pid 1 does not reap; that is dead.
    $stat = @file_get_contents("/proc/$pid/stat");
    $alive = posix_kill($pid, 0) && $stat !== false && ! preg_match('/^\d+ \(.*\) Z/', $stat);
    expect($alive)->toBeFalse();
});

it('caps output flooding', function (string $stream) {
    config(['ai.caps.stdout' => 10_000, 'ai.caps.stderr' => 10_000, 'ai.timeouts.process' => 20]);
    ($this->fake)(FakeCodex::flooding($stream));
    $t = microtime(true);
    $res = (new CodexRunner)->exec(($this->req)());
    expect($res->outputCapped)->toBeTrue()->and(microtime(true) - $t)->toBeLessThan(5.0);
})->with(['stdout', 'stderr']);

it('aborts on the callback and kills the group', function () {
    config(['ai.timeouts.process' => 20, 'ai.timeouts.abort_check_seconds' => 0]);
    ($this->fake)(FakeCodex::hanging());
    $calls = 0;
    $res = (new CodexRunner)->exec(($this->req)(), function () use (&$calls) {
        return ++$calls >= 2;
    });
    expect($res->aborted)->toBeTrue()->and($calls)->toBe(2);
});

it('flags an auth signature only for auth-like failures', function () {
    ($this->fake)(FakeCodex::failing(1, 'Error: 401 Unauthorized, please sign in'));
    $a = (new CodexRunner)->exec(($this->req)());
    expect($a->authSignature)->toBeTrue()->and($a->exitCode)->toBe(1);

    ($this->fake)(FakeCodex::failing(1, 'model overloaded'));
    $b = (new CodexRunner)->exec(($this->req)());
    expect($b->authSignature)->toBeFalse();
});

it('never keeps output text', function () {
    $canary = 'CANARY-7f3a9c-medical';
    ($this->fake)(FakeCodex::echoing($canary));
    $res = (new CodexRunner)->exec(($this->req)());
    expect(var_export($res, true))->not->toContain($canary);
});

it('reads a valid reported model and rejects bad ones', function (string $raw, ?string $expected) {
    ($this->fake)(FakeCodex::emittingModel($raw));
    expect((new CodexRunner)->exec(($this->req)())->reportedModel)->toBe($expected);
})->with([
    ['gpt-test-1', 'gpt-test-1'],
    ['bad value; rm', null],
    [str_repeat('a', 200), null],
]);

it('classifies login status', function (int $exit, string $text, LoginState $state) {
    ($this->fake)(FakeCodex::loginStatus($exit, $text));
    expect((new CodexRunner)->loginStatus())->toBe($state);
})->with([
    [0, 'Logged in using ChatGPT.', LoginState::LoggedIn],
    [0, 'Logged in using an API key - sk-***', LoginState::WrongMode],
    [1, 'Not logged in', LoginState::LoggedOut],
    [2, 'Error checking login status', LoginState::Unknown],
]);

it('reports a usage limit on stderr as flags and a reset time', function () {
    CarbonImmutable::setTestNow('2026-11-14 10:00:00');
    ($this->fake)(FakeCodex::quota("You've hit your usage limit. Please try again in 2 hours 5 minutes."));
    $res = (new CodexRunner)->exec(($this->req)());

    expect($res->quotaSignature)->toBeTrue()
        ->and($res->quotaMinutes)->toBe(60)
        ->and($res->quotaResetAt?->toIso8601String())->toBe(CarbonImmutable::parse('2026-11-14 12:06:00', 'UTC')->toIso8601String());
    CarbonImmutable::setTestNow();
});

it('reports a usage limit from a JSONL turn.failed event', function () {
    ($this->fake)(FakeCodex::quotaEvent("You've hit your usage limit."));
    expect((new CodexRunner)->exec(($this->req)())->quotaSignature)->toBeTrue();
});

it('never counts quota words that appear only in the answer', function () {
    ($this->fake)(FakeCodex::quotaInAnswer("You've hit your usage limit."));
    $res = (new CodexRunner)->exec(($this->req)());

    expect($res->quotaSignature)->toBeFalse()->and($res->quotaResetAt)->toBeNull();
});

it('keeps echoed text out of every result field and sees no quota in it', function () {
    $canary = 'CANARY-'.bin2hex(random_bytes(4));
    ($this->fake)(FakeCodex::echoing($canary));
    $res = (new CodexRunner)->exec(($this->req)());

    expect($res->quotaSignature)->toBeFalse()->and(var_export($res, true))->not->toContain($canary);
});

it('delegates the model rule to ModelId', function (string $m, bool $ok) {
    expect(CodexRunner::isValidModel($m))->toBe($ok);
})->with([['gpt-5.1', true], ['-m', false], ['a b', false], ['x=y', false], [str_repeat('a', 61), false], ['', false]]);

it('lets the caller shorten the process timeout', function () {
    config(['ai.timeouts.process' => 300]);
    ($this->fake)(FakeCodex::hanging());
    $t = microtime(true);
    $res = (new CodexRunner)->exec(($this->req)(), null, 1.0);

    expect($res->timedOut)->toBeTrue()->and(microtime(true) - $t)->toBeLessThan(4.0);
});
