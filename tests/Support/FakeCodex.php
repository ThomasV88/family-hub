<?php

namespace Tests\Support;

/** Per-test fake `codex` shell scripts. Each constructor returns the path of a 0700 temp script. */
final class FakeCodex
{
    /** Finds the -o argument. */
    private const PARSE = 'out=; prev=; for a in "$@"; do [ "$prev" = "-o" ] && out="$a"; prev="$a"; done; ';

    public static function succeeding(string $json): string
    {
        return self::script(self::PARSE.'cat >/dev/null; printf %s '.escapeshellarg($json).' > "$out"; exit 0');
    }

    public static function invalidJson(): string
    {
        return self::succeeding('{not json');
    }

    public static function emptyOutput(): string
    {
        return self::script(self::PARSE.'cat >/dev/null; : > "$out"; exit 1');
    }

    public static function exitZeroNoFile(): string
    {
        return self::script('cat >/dev/null; exit 0');
    }

    public static function failing(int $code, string $stderr): string
    {
        return self::script('cat >/dev/null; printf %s\\\\n '.escapeshellarg($stderr).' >&2; exit '.$code);
    }

    public static function hanging(): string
    {
        return self::script('while :; do sleep 1; done');
    }

    public static function silent(): string
    {
        return self::hanging();
    }

    public static function forking(string $pidFile): string
    {
        return self::script('sleep 600 & echo $! > '.escapeshellarg($pidFile).'; while :; do sleep 1; done');
    }

    public static function flooding(string $stream): string
    {
        return self::script($stream === 'stderr' ? 'yes >&2' : 'yes');
    }

    public static function recording(string $file, ?string $json = null): string
    {
        $f = escapeshellarg($file);
        $body = self::PARSE.': > '.$f.'; for a in "$@"; do printf "%s\n" "$a" >> '.$f.'; done; '
            .'echo ---ENV--- >> '.$f.'; env >> '.$f.'; echo ---PWD--- >> '.$f.'; pwd >> '.$f.'; '
            .'echo ---LS--- >> '.$f.'; ls -A >> '.$f.'; echo ---STDIN--- >> '.$f.'; cat >> '.$f.'; ';
        if ($json !== null) {
            $body .= 'printf %s '.escapeshellarg($json).' > "$out"; ';
        }

        return self::script($body.'exit 0');
    }

    public static function loginStatus(int $exit, string $text): string
    {
        return self::script('printf %s\\\\n '.escapeshellarg($text).'; exit '.$exit);
    }

    public static function echoing(string $canary): string
    {
        $c = escapeshellarg($canary);

        return self::script(self::PARSE.'cat >/dev/null; printf %s '.$c.'; printf %s '.$c.' >&2; printf %s '.$c.' > "$out"; exit 0');
    }

    /** Emits one JSONL event with the given raw model value, then exits 0. */
    public static function emittingModel(string $model): string
    {
        $line = json_encode(['type' => 'thread.started', 'model' => $model], JSON_UNESCAPED_SLASHES);

        return self::script('cat >/dev/null; printf %s\\\\n '.escapeshellarg($line));
    }

    private static function script(string $body): string
    {
        $path = sys_get_temp_dir().'/fake-codex-'.bin2hex(random_bytes(6));
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0700);

        return $path;
    }
}
