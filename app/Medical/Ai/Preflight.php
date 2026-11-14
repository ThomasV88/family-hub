<?php

namespace App\Medical\Ai;

use App\Medical\Ai\Work\WorkDir;
use Symfony\Component\Process\Process;

/** Startup / pre-spawn checks for the subscription-only rule. Returns codes only, never a value. */
final class Preflight
{
    public function envKeysClean(): bool
    {
        foreach ((array) config('ai.forbidden_env') as $name) {
            foreach ([getenv((string) $name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $v) {
                if (is_string($v) && $v !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * True when no variable that names a foreign secret (mail, Google, VAPID, cloud keys, any *_API_KEY or *_SECRET)
     * is present in the process environment. Only names are matched and nothing is returned but the verdict.
     */
    public function secretsClean(): bool
    {
        $names = array_merge(
            array_keys(getenv()),
            array_keys($_ENV),
            array_keys($_SERVER),
        );
        foreach ($names as $name) {
            $name = (string) $name;
            foreach ((array) config('ai.forbidden_env_patterns') as $pattern) {
                if (preg_match($pattern, $name) !== 1) {
                    continue;
                }
                foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $v) {
                    if (is_string($v) && $v !== '') {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /** @return list<string> */
    public function problems(): array
    {
        $out = [];

        if (! $this->envKeysClean()) {
            $out[] = 'forbidden_env';
        }
        if (! $this->secretsClean()) {
            $out[] = 'forbidden_secret';
        }
        if ((string) config('app.key') !== '') {
            $out[] = 'app_key_present';
        }

        $binary = (string) config('ai.codex.binary');
        if (! is_file($binary) || ! is_executable($binary)) {
            $out[] = 'binary_missing';
        } elseif (! $this->versionMatches($binary)) {
            $out[] = 'version_mismatch';
        }

        $home = rtrim((string) config('ai.codex.home'), '/');
        if ($home === '' || ! is_dir($home) || ! is_writable($home)) {
            $out[] = 'codex_home_unwritable';
        }
        if ($this->under($home, sys_get_temp_dir()) || $this->under($home, '/tmp')) {
            $out[] = 'codex_home_in_tmp';
        }

        if (! is_executable('/usr/bin/setsid')) {
            $out[] = 'setsid_missing';
        }
        if (! is_executable('/usr/bin/env')) {
            $out[] = 'env_missing';
        }

        if ((int) config('queue.connections.database.retry_after') <= (int) config('ai.timeouts.job') + 60) {
            $out[] = 'retry_after_too_small';
        }

        $work = WorkDir::root();
        if ($work === null || ! is_dir($work) || ! is_writable($work)) {
            $out[] = 'work_dir_unwritable';
        }

        return $out;
    }

    private function versionMatches(string $binary): bool
    {
        if (! is_executable('/usr/bin/env')) {
            return false;
        }
        $p = new Process(['/usr/bin/env', '-i', 'PATH='.dirname($binary).':/usr/bin:/bin', $binary, '--version']);
        $p->setTimeout(10);
        try {
            $p->run();
        } catch (\Throwable) {
            return false;
        }
        $expected = (string) config('ai.codex.version');

        return $p->getExitCode() === 0
            && preg_match('/(\d+\.\d+\.\d+\S*)/', $p->getOutput(), $m) === 1
            && $m[1] === $expected;
    }

    private function under(string $path, string $base): bool
    {
        $path = rtrim($path, '/');
        $base = rtrim($base, '/');

        return $path !== '' && $base !== '' && ($path === $base || str_starts_with($path, $base.'/'));
    }
}
