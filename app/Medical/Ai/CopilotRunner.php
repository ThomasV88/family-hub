<?php

namespace App\Medical\Ai;

use App\Medical\Ai\Work\WorkDir;
use Carbon\CarbonImmutable;
use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The only code that builds and spawns the copilot command. Every tool kind is denied, the built-in MCP servers are off,
 * the environment is an exact allowlist (`env -i`) without any token variable, and the CLI runs with a throwaway home
 * inside the work dir, so everything it writes (session logs, device id) disappears with the work dir. The persistent
 * copilot home holds the login only and is read, never written. Only counters, flags and times leave this class.
 */
final class CopilotRunner
{
    private const WINDOW = 8192;

    private const POLL_US = 250_000;

    public function exec(CopilotRequest $r, ?Closure $shouldAbort = null): ProcessResult
    {
        $this->assertWrappers();

        $home = $this->prepare($r->workDir);
        $tmp = $r->workDir.'/tmp';
        $reply = $r->workDir.'/reply.txt';
        file_put_contents($reply, '');
        chmod($reply, 0600);

        $args = [
            '-p', $r->prompt, '-s', '--no-ask-user', '--no-color', '--stream', 'off',
            '--available-tools',
            '--deny-tool=shell', '--deny-tool=write', '--deny-tool=read', '--deny-tool=url', '--deny-tool=memory',
            '--disable-builtin-mcps', '--disable-mcp-server', 'github-mcp-server',
            '--no-custom-instructions', '--disallow-temp-dir', '--no-auto-update', '--disable-client-telemetry',
            '--no-experimental', '--log-level', 'none', '--log-dir', $home.'/logs', '--sync-session=off',
            '--max-ai-credits', (string) (int) config('ai.copilot.max_ai_credits'),
            '--usage-output-file', $r->usagePath,
        ];
        if ($r->model !== null) {
            array_push($args, '--model', $r->model);
        }
        foreach ($r->images as $image) {
            array_push($args, '--attachment', $image);
        }

        $p = new Process($this->wrap($home, $tmp, $args), $r->workDir);
        $p->setTimeout((float) max(1, $r->timeoutSeconds))
            ->setIdleTimeout((float) config('ai.timeouts.idle'));

        $capOut = (int) config('ai.caps.result');
        $capErr = (int) config('ai.caps.stderr');
        $every = (float) config('ai.timeouts.abort_check_seconds');
        $started = hrtime(true);

        $p->start();
        $pid = $p->getPid();

        $out = $err = 0;
        $errWindow = '';
        $timedOut = $idleTimedOut = $aborted = $capped = false;
        $lastCheck = null;

        $scan = function () use ($p, $reply, &$out, &$err, &$errWindow): void {
            $o = $p->getIncrementalOutput();
            $e = $p->getIncrementalErrorOutput();
            if ($o !== '') {
                $out += strlen($o);
                // Past the cap the file stops growing; the loop kills the run right after.
                if ($out <= (int) config('ai.caps.result') + strlen($o)) {
                    file_put_contents($reply, $o, FILE_APPEND);
                }
            }
            if ($e !== '') {
                $err += strlen($e);
                $errWindow = substr($errWindow.$e, -self::WINDOW);
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
        // The login copy and everything the CLI wrote go now, before the reply is read and before any later call or agent
        // runs in this work dir. The next exec (the repair call) seeds a fresh home.
        $this->discardScratch($home, $tmp);

        if ($out > $capOut) {
            $capped = true;
        }
        if ($capped) {
            // An over-cap reply is never read.
            file_put_contents($reply, '');
        }

        $exit = ($timedOut || $idleTimedOut || $aborted || $capped) ? null : $p->getExitCode();
        $auth = false;
        $quota = null;
        // Only stderr, only after a failing exit: a document that makes the model print "usage limit" cannot park a run.
        if ($exit !== null && $exit !== 0 && $errWindow !== '') {
            $auth = $this->matchesAuth($errWindow);
            $quota = QuotaSignatures::match('copilot', $errWindow, CarbonImmutable::now());
        }

        return new ProcessResult(
            exitCode: $exit,
            timedOut: $timedOut,
            idleTimedOut: $idleTimedOut,
            aborted: $aborted,
            outputCapped: $capped,
            authSignature: $auth,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            reportedModel: null,
            quotaSignature: $quota !== null,
            quotaMinutes: $quota['minutes'] ?? null,
            quotaResetAt: $quota['resetAt'] ?? null,
        );
    }

    /**
     * Login health from the local credential marker; the CLI is never run and no value is kept. The file is JSONC.
     */
    public function loginStatus(): LoginState
    {
        $file = rtrim((string) config('ai.copilot.home'), '/').'/config.json';
        if (! is_file($file)) {
            return LoginState::LoggedOut;
        }
        $raw = @file_get_contents($file, false, null, 0, 1_048_576);
        if ($raw === false) {
            return LoginState::Unknown;
        }
        $lines = array_filter(
            preg_split('/\r\n|\n/', $raw) ?: [],
            fn (string $line) => ! str_starts_with(ltrim($line), '//'),
        );
        try {
            $data = json_decode(implode("\n", $lines), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return LoginState::Unknown;
        }
        if (! is_array($data)) {
            return LoginState::Unknown;
        }
        foreach ((array) config('ai.copilot.login_keys') as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== [] && $value !== false) {
                return LoginState::LoggedIn;
            }
        }

        return LoginState::LoggedOut;
    }

    /** Creates the per-run home inside the work dir and seeds it with the login and an empty settings file. */
    private function prepare(string $workDir): string
    {
        $home = $workDir.'/copilot-home';
        foreach ([$home, $home.'/logs', $workDir.'/tmp'] as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException('ai runner unavailable');
            }
            chmod($dir, 0700);
        }
        file_put_contents($home.'/settings.json', '{}');
        chmod($home.'/settings.json', 0600);

        $source = rtrim((string) config('ai.copilot.home'), '/').'/config.json';
        if (is_file($source)) {
            $bytes = @file_get_contents($source);
            if ($bytes !== false) {
                file_put_contents($home.'/config.json', $bytes);
                chmod($home.'/config.json', 0600);
            }
        }

        return $home;
    }

    /** Removes the per-run home (with the login copy) and empties the per-run temp dir; the dir itself stays for the work dir owner. */
    private function discardScratch(string $home, string $tmp): void
    {
        WorkDir::removeTree($home);
        foreach (scandir($tmp) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                WorkDir::removeTree($tmp.'/'.$entry);
            }
        }
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function wrap(string $home, string $tmp, array $args): array
    {
        $binary = (string) config('ai.copilot.binary');

        return [
            '/usr/bin/env', '-i',
            'PATH='.dirname($binary).':/usr/bin:/bin',
            'HOME='.$home,
            'COPILOT_HOME='.$home,
            'COPILOT_CACHE_HOME='.(string) config('ai.copilot.cache'),
            'COPILOT_AUTO_UPDATE=false',
            'COPILOT_TELEMETRY_DISABLED=true',
            'NO_COLOR=1',
            'TZ=UTC',
            'LANG=C.UTF-8',
            'TMPDIR='.$tmp,
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
        foreach ((array) config('ai.copilot.auth_failure_patterns') as $needle) {
            if ($needle !== '' && str_contains($text, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
