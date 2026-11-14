<?php

namespace App\Medical\Ai;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The only code that builds and spawns the codex command. The environment is replaced by an exact allowlist (`env -i`),
 * the child is a session and group leader (`setsid`) so a group kill leaves no orphan, and nothing the CLI prints is kept:
 * only counters, flags and a validated model identifier leave this class.
 */
final class CodexRunner
{
    private const WINDOW = 8192;

    private const POLL_US = 250_000;

    public function exec(CodexRequest $r, ?Closure $shouldAbort = null): ProcessResult
    {
        $this->assertWrappers();

        $tmp = $r->workDir.'/tmp';
        if (! is_dir($tmp)) {
            mkdir($tmp, 0700, true);
        }
        chmod($tmp, 0700);

        $args = [
            'exec', '--sandbox', 'read-only', '--ephemeral', '--skip-git-repo-check', '--ignore-user-config',
            '--ignore-rules', '--color', 'never', '--json', '--output-schema', $r->schemaPath, '-o', $r->resultPath,
        ];
        if ($r->model !== null) {
            array_push($args, '-m', $r->model);
        }
        array_push(
            $args,
            '-c', 'web_search="disabled"',
            '-c', 'features.shell_tool=false',
            '-c', 'features.apps=false',
            '-c', 'forced_login_method="chatgpt"',
            '-c', 'history.persistence="none"',
            '-c', 'analytics.enabled=false',
            '-c', 'check_for_update_on_startup=false',
            '--disable', 'memories',
        );
        if ($r->images !== []) {
            array_push($args, '-i', implode(',', $r->images));
        }

        $p = new Process($this->wrap($r->workDir, $args, $tmp), $r->workDir);
        $p->setTimeout((float) config('ai.timeouts.process'))
            ->setIdleTimeout((float) config('ai.timeouts.idle'))
            ->setInput($r->prompt);

        $capOut = (int) config('ai.caps.stdout');
        $capErr = (int) config('ai.caps.stderr');
        $every = (float) config('ai.timeouts.abort_check_seconds');
        $started = hrtime(true);

        $p->start();
        $pid = $p->getPid();

        $out = $err = 0;
        $errWindow = '';
        $outLine = '';
        $auth = $timedOut = $idleTimedOut = $aborted = $capped = false;
        $model = null;
        $lastCheck = null;

        $scan = function () use ($p, &$out, &$err, &$errWindow, &$outLine, &$auth, &$model): void {
            $o = $p->getIncrementalOutput();
            $e = $p->getIncrementalErrorOutput();
            $out += strlen($o);
            $err += strlen($e);

            if ($e !== '') {
                $errWindow = substr($errWindow.$e, -self::WINDOW);
                $auth = $auth || $this->matchesAuth($errWindow);
                if ($model === null && preg_match('/^model:\s*(\S+)\s*$/mi', $errWindow, $m) === 1) {
                    $model = $this->validModel($m[1]);
                }
            }
            if ($o !== '') {
                $outLine .= $o;
                $parts = explode("\n", $outLine);
                $outLine = substr((string) array_pop($parts), -self::WINDOW);
                foreach ($parts as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] !== '{') {
                        continue;
                    }
                    $j = json_decode($line, true, 16);
                    if (! is_array($j)) {
                        continue;
                    }
                    if ($model === null && isset($j['model']) && is_string($j['model'])) {
                        $model = $this->validModel($j['model']);
                    }
                    if (in_array($j['type'] ?? null, ['error', 'turn.failed'], true)) {
                        $auth = $auth || $this->matchesAuth(substr($line, -self::WINDOW));
                    }
                }
            }
        };

        while ($p->isRunning()) {
            $scan();
            if ($out > $capOut || $err > $capErr) {
                $capped = true;
                $this->killGroup($p, $pid);
                break;
            }
            if ($shouldAbort !== null && ($lastCheck === null || microtime(true) - $lastCheck >= $every)) {
                $lastCheck = microtime(true);
                if ($shouldAbort() === true) {
                    $aborted = true;
                    $this->killGroup($p, $pid);
                    break;
                }
            }
            try {
                $p->checkTimeout();
            } catch (ProcessTimedOutException $e) {
                $idleTimedOut = $e->isIdleTimeout();
                $timedOut = ! $idleTimedOut;
                $this->killGroup($p, $pid);
                break;
            }
            usleep(self::POLL_US);
        }

        $scan();
        // Reap, and sweep any grandchild that outlived the CLI.
        $this->killGroup($p, $pid);

        return new ProcessResult(
            exitCode: ($timedOut || $idleTimedOut || $aborted || $capped) ? null : $p->getExitCode(),
            timedOut: $timedOut,
            idleTimedOut: $idleTimedOut,
            aborted: $aborted,
            outputCapped: $capped,
            authSignature: $auth,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            reportedModel: $model,
        );
    }

    public function loginStatus(): LoginState
    {
        $this->assertWrappers();
        $workDir = sys_get_temp_dir();

        $p = new Process($this->wrap($workDir, ['login', 'status'], $workDir), $workDir);
        $p->setTimeout((float) config('ai.timeouts.login'));
        try {
            $p->run();
        } catch (ProcessTimedOutException) {
            $this->killGroup($p, null);

            return LoginState::Unknown;
        }
        $text = $p->getOutput().$p->getErrorOutput();
        $code = $p->getExitCode();

        return match (true) {
            $code === 0 && str_contains($text, 'Logged in using ChatGPT') => LoginState::LoggedIn,
            $code === 0 => LoginState::WrongMode,
            $code === 1 && str_contains($text, 'Not logged in') => LoginState::LoggedOut,
            default => LoginState::Unknown,
        };
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function wrap(string $workDir, array $args, string $tmpDir): array
    {
        $binary = (string) config('ai.codex.binary');
        $home = (string) config('ai.codex.home');

        return [
            '/usr/bin/env', '-i',
            'PATH='.dirname($binary).':/usr/bin:/bin',
            'HOME='.$home,
            'CODEX_HOME='.$home,
            'LANG=C.UTF-8',
            'TMPDIR='.$tmpDir,
            '/usr/bin/setsid', $binary, ...$args,
        ];
    }

    private function assertWrappers(): void
    {
        if (! is_executable('/usr/bin/env') || ! is_executable('/usr/bin/setsid')) {
            throw new RuntimeException('ai runner unavailable');
        }
    }

    private function killGroup(Process $p, ?int $pid): void
    {
        $pid ??= $p->getPid();
        if ($pid !== null && $pid > 1 && function_exists('posix_kill')) {
            @posix_kill(-$pid, SIGKILL);
        }
        $p->stop(0, SIGKILL);
    }

    private function matchesAuth(string $text): bool
    {
        $text = strtolower($text);
        foreach ((array) config('ai.auth_failure_patterns') as $needle) {
            if ($needle !== '' && str_contains($text, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    private function validModel(string $value): ?string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,60}$/', $value) === 1 ? $value : null;
    }
}
