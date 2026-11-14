<?php

use App\Medical\Ai\CopilotRequest;
use App\Medical\Ai\CopilotRunner;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\Work\WorkDir;
use Illuminate\Support\Str;
use Tests\Support\FakeCopilot;
use Tests\TestCase;

uses(TestCase::class);

function copilotFilesContaining(string $dir, string $needle): array
{
    $hits = [];
    if (! is_dir($dir)) {
        return $hits;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && str_contains((string) file_get_contents($f->getPathname()), $needle)) {
            $hits[] = $f->getPathname();
        }
    }

    return $hits;
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/ai-cp-'.strtolower((string) Str::ulid());
    $this->persist = $this->root.'-persist';
    $this->scripts = [];
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'-codex',
        'ai.copilot.home' => $this->persist, 'ai.copilot.cache' => $this->root.'-cache',
        'ai.timeouts.process' => 30, 'ai.timeouts.idle' => 30,
    ]);
    $this->dir = WorkDir::create();
    $this->fake = function (string $path) {
        $this->scripts[] = $path;
        config(['ai.copilot.binary' => $path]);

        return $path;
    };
    $this->req = fn (?string $model = null, array $images = [], int $timeout = 30) => new CopilotRequest(
        $this->dir->path(), 'PROMPT TEXT', $images, $model, $timeout, $this->dir->path().'/usage.json',
    );
});

afterEach(function () {
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
    WorkDir::removeTree($this->root);
    WorkDir::removeTree($this->persist);
});

it('records the denial flags, scrubbed env, per-run home, cwd and closed stdin', function () {
    FakeCopilot::loginHome($this->persist, true);
    $rec = $this->root.'-rec.txt';
    $this->scripts[] = $rec;
    ($this->fake)(FakeCopilot::recording($rec, '{"ok":true}'));
    $this->dir->put('page-1.jpg', 'x');
    $this->dir->put('page-2.jpg', 'x');
    $images = [$this->dir->file('page-1.jpg'), $this->dir->file('page-2.jpg')];
    $secrets = ['GH_TOKEN' => 'gh-secret', 'GITHUB_TOKEN' => 'gh-secret2', 'COPILOT_GITHUB_TOKEN' => 'gh-secret3', 'OPENAI_API_KEY' => 'sk-secret'];
    foreach ($secrets as $k => $v) {
        putenv("$k=$v");
    }

    try {
        $res = (new CopilotRunner)->exec(($this->req)(null, $images));
    } finally {
        foreach ($secrets as $k => $v) {
            putenv($k);
        }
    }

    expect($res->exitCode)->toBe(0)
        ->and($res->timedOut || $res->idleTimedOut || $res->aborted || $res->outputCapped || $res->authSignature || $res->quotaSignature)->toBeFalse()
        ->and(file_get_contents($this->dir->file('reply.txt')))->toBe('{"ok":true}');

    [$argv, $rest] = explode("---ENV---\n", (string) file_get_contents($rec), 2);
    [$env, $rest] = explode("---PWD---\n", $rest, 2);
    [$pwd, $rest] = explode("---LS---\n", $rest, 2);
    [$ls, $rest] = explode("---HOMELS---\n", $rest, 2);
    [$homeLs, $rest] = explode("---MODE---\n", $rest, 2);
    [$mode, $stdin] = explode("---STDIN---\n", $rest, 2);
    $args = explode("\n", trim($argv));

    $work = $this->dir->path();
    expect($args)->toBe([
        '-p', 'PROMPT TEXT', '-s', '--no-ask-user', '--no-color', '--stream', 'off', '--available-tools',
        '--deny-tool=shell', '--deny-tool=write', '--deny-tool=read', '--deny-tool=url', '--deny-tool=memory',
        '--disable-builtin-mcps', '--disable-mcp-server', 'github-mcp-server', '--no-custom-instructions',
        '--disallow-temp-dir', '--no-auto-update', '--disable-client-telemetry', '--no-experimental',
        '--log-level', 'none', '--log-dir', $work.'/copilot-home/logs', '--sync-session=off',
        '--max-ai-credits', '60', '--usage-output-file', $work.'/usage.json',
        '--attachment', $work.'/page-1.jpg', '--attachment', $work.'/page-2.jpg',
    ]);
    foreach (['--allow-all', '--yolo', '--allow-tool', '--allow-all-tools', '--allow-all-paths', '--allow-all-urls', '--trust-folder', '--share', '--resume', '--continue', '--autopilot', '--model'] as $banned) {
        expect($args)->not->toContain($banned);
    }

    $keys = array_map(fn ($l) => explode('=', $l, 2)[0], array_filter(explode("\n", trim($env))));
    sort($keys);
    $expected = [
        'COPILOT_AUTO_UPDATE', 'COPILOT_CACHE_HOME', 'COPILOT_HOME', 'COPILOT_TELEMETRY_DISABLED', 'HOME', 'LANG',
        'NO_COLOR', 'PATH', 'TMPDIR', 'TZ',
    ];
    expect(array_diff($keys, [...$expected, 'PWD', 'SHLVL', '_', 'OLDPWD']))->toBe([])
        ->and(array_diff($expected, $keys))->toBe([])
        ->and($env)->not->toContain('gh-secret')->not->toContain('sk-secret')
        ->and($env)->toContain('COPILOT_HOME='.$work.'/copilot-home')->toContain('HOME='.$work.'/copilot-home')
        ->and($env)->toContain('COPILOT_CACHE_HOME='.$this->root.'-cache')->toContain('TMPDIR='.$work.'/tmp')
        ->and($env)->not->toContain('COPILOT_HOME='.$this->persist)
        ->and(trim($pwd))->toBe(realpath($work))
        ->and(array_diff(explode("\n", trim($ls)), ['page-1.jpg', 'page-2.jpg', 'reply.txt', 'tmp', 'copilot-home']))->toBe([])
        ->and(explode("\n", trim($ls)))->toContain('page-1.jpg', 'page-2.jpg')
        ->and(explode("\n", trim($homeLs)))->toContain('config.json', 'settings.json')
        ->and(trim($mode))->toBe('600')
        ->and($stdin)->toBe('');
});

it('adds --model only when a model is given', function () {
    $rec = $this->root.'-rec.txt';
    $this->scripts[] = $rec;
    ($this->fake)(FakeCopilot::recording($rec));
    (new CopilotRunner)->exec(($this->req)(null));
    expect(file_get_contents($rec))->not->toContain("\n--model\n");

    (new CopilotRunner)->exec(($this->req)('claude-haiku-4.5'));
    expect(file_get_contents($rec))->toContain("\n--model\nclaude-haiku-4.5\n");
});

it('works without a persistent login file', function () {
    $rec = $this->root.'-rec.txt';
    $this->scripts[] = $rec;
    ($this->fake)(FakeCopilot::recording($rec));
    (new CopilotRunner)->exec(($this->req)());
    expect(is_file($this->dir->path().'/copilot-home/config.json'))->toBeFalse();
});

it('removes everything the CLI writes with the work dir and keeps the persistent home login-only', function () {
    FakeCopilot::loginHome($this->persist, true);
    $canary = 'CANARY-'.bin2hex(random_bytes(4));
    ($this->fake)(FakeCopilot::writesSession($canary));
    (new CopilotRunner)->exec(($this->req)());

    // The run's home and temp dir are gone as soon as the process ends, not only with the work dir.
    expect(copilotFilesContaining($this->dir->path(), $canary))->toBe([])
        ->and(is_dir($this->dir->path().'/copilot-home'))->toBeFalse();
    $this->dir->cleanup();

    expect(copilotFilesContaining($this->root, $canary))->toBe([])
        ->and(copilotFilesContaining($this->persist, $canary))->toBe([])
        ->and(scandir($this->persist))->toBe(['.', '..', 'config.json']);
});

it('gives a second call (the repair call) a fresh home with none of the first call\'s files or temp files', function () {
    FakeCopilot::loginHome($this->persist, true);
    ($this->fake)(FakeCopilot::script('mkdir -p "$COPILOT_HOME/session-state/x"; printf first > "$COPILOT_HOME/session-state/x/e"; printf first > "$TMPDIR/scratch"; printf %s "{}"; exit 0'));
    (new CopilotRunner)->exec(($this->req)());
    expect(scandir($this->dir->path().'/tmp'))->toBe(['.', '..']);

    $rec = $this->root.'-rec.txt';
    $this->scripts[] = $rec;
    ($this->fake)(FakeCopilot::script(': > '.escapeshellarg($rec).'; ls -A "$COPILOT_HOME" >> '.escapeshellarg($rec).'; echo --- >> '.escapeshellarg($rec).'; ls -A "$TMPDIR" >> '.escapeshellarg($rec).'; exit 0'));
    (new CopilotRunner)->exec(($this->req)());

    [$home, $tmp] = explode("---\n", (string) file_get_contents($rec), 2);
    expect(array_filter(explode("\n", trim($home))))->each->not->toBe('session-state')
        ->and(trim($tmp))->toBe('');
});

it('caps output flooding and bounds stderr', function (string $stream) {
    config(['ai.caps.result' => 10_000, 'ai.caps.stderr' => 10_000, 'ai.timeouts.process' => 20]);
    ($this->fake)(FakeCopilot::flooding($stream));
    $t = microtime(true);
    $res = (new CopilotRunner)->exec(($this->req)(null, [], 20));

    expect($res->outputCapped)->toBeTrue()
        ->and($res->exitCode)->toBeNull()
        ->and(microtime(true) - $t)->toBeLessThan(5.0)
        ->and(filesize($this->dir->file('reply.txt')))->toBe(0);
})->with(['stdout', 'stderr']);

it('times out a hanging run and kills the group', function () {
    ($this->fake)(FakeCopilot::hanging());
    $t = microtime(true);
    $res = (new CopilotRunner)->exec(($this->req)(null, [], 1));
    expect($res->timedOut)->toBeTrue()->and($res->idleTimedOut)->toBeFalse()
        ->and(microtime(true) - $t)->toBeLessThan(4.0);
});

it('idle-times-out a silent run', function () {
    config(['ai.timeouts.idle' => 1]);
    ($this->fake)(FakeCopilot::hanging());
    $res = (new CopilotRunner)->exec(($this->req)());
    expect($res->idleTimedOut)->toBeTrue()->and($res->timedOut)->toBeFalse();
});

it('aborts on the callback', function () {
    config(['ai.timeouts.abort_check_seconds' => 0]);
    ($this->fake)(FakeCopilot::hanging());
    $calls = 0;
    $res = (new CopilotRunner)->exec(($this->req)(), function () use (&$calls) {
        return ++$calls >= 2;
    });
    expect($res->aborted)->toBeTrue()->and($calls)->toBe(2)->and($res->exitCode)->toBeNull();
});

it('flags an auth failure from stderr', function () {
    ($this->fake)(FakeCopilot::unauthenticated());
    $res = (new CopilotRunner)->exec(($this->req)());
    expect($res->authSignature)->toBeTrue()->and($res->exitCode)->toBe(1)->and($res->quotaSignature)->toBeFalse();
});

it('flags a quota signature with minutes and a reset time from stderr only', function () {
    ($this->fake)(FakeCopilot::quota("You've hit your session rate limit. Try again in 3 hours."));
    $res = (new CopilotRunner)->exec(($this->req)());
    expect($res->quotaSignature)->toBeTrue()->and($res->quotaMinutes)->toBe(60)
        ->and($res->quotaResetAt)->not->toBeNull()->and($res->authSignature)->toBeFalse();

    ($this->fake)(FakeCopilot::echoQuotaOnStdout());
    $res = (new CopilotRunner)->exec(($this->req)());
    expect($res->quotaSignature)->toBeFalse()->and($res->exitCode)->toBe(0);
});

it('never keeps output text', function () {
    $canary = 'CANARY-7f3a9c-medical';
    ($this->fake)(FakeCopilot::echoing($canary));
    $res = (new CopilotRunner)->exec(($this->req)());
    expect(var_export($res, true))->not->toContain($canary);
});

it('reads the login marker without running anything', function () {
    config(['ai.copilot.binary' => '/nonexistent/copilot']);

    FakeCopilot::loginHome($this->persist, true);
    expect((new CopilotRunner)->loginStatus())->toBe(LoginState::LoggedIn);

    FakeCopilot::loginHome($this->persist, false);
    expect((new CopilotRunner)->loginStatus())->toBe(LoginState::LoggedOut);

    unlink($this->persist.'/config.json');
    expect((new CopilotRunner)->loginStatus())->toBe(LoginState::LoggedOut);

    file_put_contents($this->persist.'/config.json', "// c\n{not json");
    expect((new CopilotRunner)->loginStatus())->toBe(LoginState::Unknown);

    file_put_contents($this->persist.'/config.json', '{"last_logged_in_user":""}');
    expect((new CopilotRunner)->loginStatus())->toBe(LoginState::LoggedOut);
});
