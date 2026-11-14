<?php

namespace Tests\Support;

/**
 * Per-test fake `copilot` shell scripts. Each constructor returns the path of a 0700 temp script. The scripts parse -p
 * and --usage-output-file like the real CLI and never touch the network.
 */
final class FakeCopilot
{
    /** Finds the -p and --usage-output-file values. */
    private const PARSE = 'prompt=; usage=; prev=; for a in "$@"; do [ "$prev" = "-p" ] && prompt="$a"; [ "$prev" = "--usage-output-file" ] && usage="$a"; prev="$a"; done; ';

    public static function replying(string $stdout): string
    {
        return self::script('printf %s '.escapeshellarg($stdout).'; exit 0');
    }

    public static function fenced(string $json): string
    {
        return self::replying("```json\n".$json."\n```");
    }

    /** Call N prints reply N (the last reply repeats); the prompt of call N is saved in "<counterFile>.pN". */
    public static function sequence(array $replies, string $counterFile): string
    {
        $c = escapeshellarg($counterFile);
        $body = self::PARSE.'n=0; [ -f '.$c.' ] && n=$(cat '.$c.'); n=$((n+1)); printf %s "$n" > '.$c.'; '
            .'printf %s "$prompt" > '.escapeshellarg($counterFile).'.p"$n"; case $n in ';
        $replies = array_values($replies);
        foreach ($replies as $i => $reply) {
            $pattern = $i === count($replies) - 1 ? '*' : (string) ($i + 1);
            $body .= $pattern.') printf %s '.escapeshellarg($reply).' ;; ';
        }

        return self::script($body.'esac; exit 0');
    }

    public static function unauthenticated(): string
    {
        return self::script('printf %s\\\\n '.escapeshellarg('Error: No authentication information found.').' >&2; exit 1');
    }

    public static function quota(string $stderr): string
    {
        return self::script('printf %s\\\\n '.escapeshellarg($stderr).' >&2; exit 1');
    }

    /** A quota message on stdout with exit 0: document-derived text that must never count as quota. */
    public static function echoQuotaOnStdout(): string
    {
        return self::replying("You've hit your session rate limit. Please try again in 3 hours.");
    }

    /** Records argv (one per line), env, cwd, listings of cwd and COPILOT_HOME, the config mode and stdin. */
    public static function recording(string $file, ?string $reply = null): string
    {
        $f = escapeshellarg($file);
        $body = ': > '.$f.'; for a in "$@"; do printf "%s\n" "$a" >> '.$f.'; done; '
            .'echo ---ENV--- >> '.$f.'; env >> '.$f.'; echo ---PWD--- >> '.$f.'; pwd >> '.$f.'; '
            .'echo ---LS--- >> '.$f.'; ls -A >> '.$f.'; echo ---HOMELS--- >> '.$f.'; ls -A "$COPILOT_HOME" >> '.$f.'; '
            .'echo ---MODE--- >> '.$f.'; stat -c %a "$COPILOT_HOME/config.json" >> '.$f.' 2>&1; '
            .'echo ---STDIN--- >> '.$f.'; cat >> '.$f.'; ';
        if ($reply !== null) {
            $body .= 'printf %s '.escapeshellarg($reply).'; ';
        }

        return self::script($body.'exit 0');
    }

    public static function hanging(): string
    {
        return self::script('while :; do sleep 1; done');
    }

    public static function flooding(string $stream): string
    {
        return self::script($stream === 'stderr' ? 'yes >&2' : 'yes');
    }

    /** The canary goes to stdout and stderr, never as a valid reply. */
    public static function echoing(string $canary): string
    {
        $c = escapeshellarg($canary);

        return self::script('printf %s '.$c.'; printf %s '.$c.' >&2; exit 0');
    }

    /** Writes the usage JSON to --usage-output-file and prints the optional reply. */
    public static function usageFile(string $json, ?string $stdout = null): string
    {
        $body = self::PARSE.'printf %s '.escapeshellarg($json).' > "$usage"; ';
        if ($stdout !== null) {
            $body .= 'printf %s '.escapeshellarg($stdout).'; ';
        }

        return self::script($body.'exit 0');
    }

    /** Writes the canary where the real CLI keeps its session event log. */
    public static function writesSession(string $canary): string
    {
        return self::script('mkdir -p "$COPILOT_HOME/session-state/x"; printf %s '.escapeshellarg($canary).' > "$COPILOT_HOME/session-state/x/events.jsonl"; exit 0');
    }

    /** Builds "<dir>/config.json" in the JSONC shape the CLI writes. Returns $dir. */
    public static function loginHome(string $dir, bool $loggedIn): string
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $json = $loggedIn
            ? ['last_logged_in_user' => ['host' => 'https://github.com', 'login' => 'family'], 'banner' => 'never']
            : ['banner' => 'never'];
        file_put_contents($dir.'/config.json', "// User settings belong in settings.json.\n// This file is managed automatically.\n".json_encode($json, JSON_PRETTY_PRINT));

        return $dir;
    }

    private static function script(string $body): string
    {
        $path = sys_get_temp_dir().'/fake-copilot-'.bin2hex(random_bytes(6));
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0700);

        return $path;
    }
}
