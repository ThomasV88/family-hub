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

    /** Usage limit on stderr, exit 1, no result file. */
    public static function quota(string $stderr): string
    {
        return self::script('cat >/dev/null; printf %s\\\\n '.escapeshellarg($stderr).' >&2; exit 1');
    }

    /** A JSONL turn.failed event carrying the message, exit 1. */
    public static function quotaEvent(string $message): string
    {
        $line = json_encode(['type' => 'turn.failed', 'error' => ['message' => $message]], JSON_UNESCAPED_SLASHES);

        return self::script('cat >/dev/null; printf %s\\\\n '.escapeshellarg($line).'; exit 1');
    }

    /** The message appears only in the answer (invalid -o file and stdout), exit 0. */
    public static function quotaInAnswer(string $message): string
    {
        $m = escapeshellarg($message);

        return self::script(self::PARSE.'cat >/dev/null; printf %s '.$m.'; printf %s '.$m.' > "$out"; exit 0');
    }

    /** A golden result file plus quota text on stderr, exit 0. */
    public static function quotaWithValidResult(string $stderr, string $json): string
    {
        return self::script(self::PARSE.'cat >/dev/null; printf %s\\\\n '.escapeshellarg($stderr).' >&2; printf %s '.escapeshellarg($json).' > "$out"; exit 0');
    }

    /** Emits one JSONL event with the given raw model value, then exits 0. */
    public static function emittingModel(string $model): string
    {
        $line = json_encode(['type' => 'thread.started', 'model' => $model], JSON_UNESCAPED_SLASHES);

        return self::script('cat >/dev/null; printf %s\\\\n '.escapeshellarg($line));
    }

    /**
     * Like succeeding(), and also prints a JSONL turn.completed event with these usage numbers on stdout.
     *
     * @param  array<string, mixed>  $usage  input_tokens, cached_input_tokens, output_tokens, reasoning_output_tokens
     */
    public static function withUsage(string $json, array $usage): string
    {
        $event = json_encode(['type' => 'turn.completed', 'usage' => $usage], JSON_UNESCAPED_SLASHES);

        return self::script(self::PARSE.'cat >/dev/null; printf %s\\\\n '.escapeshellarg($event).'; printf %s '.escapeshellarg($json).' > "$out"; exit 0');
    }

    /**
     * Answers by the --output-schema file it receives (the agents copy every schema to the same file name, so the
     * content decides, not the name): the key is the file name of a schema in resources/ai or resources/ai/eval, and the
     * value is the reply written to the -o file. `login status` answers "Logged in using ChatGPT". Options:
     * usage (array: emits a turn.completed line), log (file: one line per exec call), quota_after (int: from the
     * N+1th exec call on, a usage-limit message on stderr and exit 1), fail_after (int: the same with a plain CLI error).
     *
     * @param  array<string, string>  $replyBySchemaFile
     * @param  array{usage?: array<string, int>, log?: string, quota_after?: int, fail_after?: int}  $options
     */
    public static function bySchema(array $replyBySchemaFile, array $options = []): string
    {
        $body = 'if [ "$1" = "login" ]; then echo "Logged in using ChatGPT"; exit 0; fi; '.self::PARSE
            .'schema=; prev=; for a in "$@"; do [ "$prev" = "--output-schema" ] && schema="$a"; prev="$a"; done; cat >/dev/null; ';
        if (isset($options['log'])) {
            $body .= 'echo call >> '.escapeshellarg($options['log']).'; n=$(wc -l < '.escapeshellarg($options['log']).'); ';
        } else {
            $body .= 'n=0; ';
        }
        if (isset($options['quota_after'])) {
            $body .= 'if [ "$n" -gt '.(int) $options['quota_after'].' ]; then echo "You\'ve hit your usage limit. Try again later." >&2; exit 1; fi; ';
        }
        if (isset($options['fail_after'])) {
            $body .= 'if [ "$n" -gt '.(int) $options['fail_after'].' ]; then echo "unexpected failure" >&2; exit 1; fi; ';
        }
        $body .= 'sum=$(md5sum "$schema" | cut -d" " -f1); ';
        if (isset($options['usage'])) {
            $event = json_encode(['type' => 'turn.completed', 'usage' => $options['usage']], JSON_UNESCAPED_SLASHES);
            $body .= 'printf %s\\\\n '.escapeshellarg($event).'; ';
        }
        $body .= 'case "$sum" in ';
        foreach ($replyBySchemaFile as $file => $json) {
            $path = is_file(resource_path('ai/'.$file)) ? resource_path('ai/'.$file) : resource_path('ai/eval/'.$file);
            $body .= md5_file($path).') printf %s '.escapeshellarg($json).' > "$out";; ';
        }
        $body .= '*) : > "$out";; esac; exit 0';

        return self::script($body);
    }

    /**
     * Answers the transcription schema with one page per received image (the -i argument is a comma separated list):
     * the page text is `<prefix> <absolute page number>`, the absolute counter lives in the state file and only moves on a
     * successful call, so a test can assert order and resume. Every exec call appends `T <images>` (transcription) or `X`
     * (any other schema) to the log file. Options: extract (reply for the extraction schema), fail_nth (the Nth
     * transcription call fails once) with fail_kind invalid (default), quota or error.
     *
     * @param  array{extract?: string, fail_nth?: int, fail_kind?: string}  $options
     */
    public static function transcribing(string $state, string $log, string $prefix = 'Page', array $options = []): string
    {
        $st = escapeshellarg($state);
        $lg = escapeshellarg($log);
        $transcription = md5_file(resource_path('ai/transcription.v1.schema.json'));
        $body = 'if [ "$1" = "login" ]; then echo "Logged in using ChatGPT"; exit 0; fi; '.self::PARSE
            .'schema=; imgs=; prev=; for a in "$@"; do [ "$prev" = "--output-schema" ] && schema="$a"; [ "$prev" = "-i" ] && imgs="$a"; prev="$a"; done; cat >/dev/null; '
            .'sum=$(md5sum "$schema" | cut -d" " -f1); '
            .'if [ "$sum" = '.$transcription.' ]; then '
            .'ni=0; [ -n "$imgs" ] && ni=$(printf "%s\\n" "$imgs" | tr "," "\n" | wc -l); '
            .'echo "T $ni" >> '.$lg.'; n=$(grep -c "^T " '.$lg.'); ';
        if (isset($options['fail_nth'])) {
            $fail = match ($options['fail_kind'] ?? 'invalid') {
                'quota' => 'echo "You\'ve hit your usage limit. Try again later." >&2; exit 1;',
                'error' => 'echo "unexpected failure" >&2; exit 1;',
                default => 'printf %s "{not json" > "$out"; exit 0;',
            };
            $body .= 'if [ "$n" -eq '.(int) $options['fail_nth'].' ]; then '.$fail.' fi; ';
        }
        $body .= 'base=0; [ -f '.$st.' ] && base=$(cat '.$st.'); '
            .'json="{\"schema_version\":1,\"language\":\"sk\",\"pages\":["; i=1; '
            .'while [ "$i" -le "$ni" ]; do abs=$((base+i)); [ "$i" -gt 1 ] && json="$json,"; '
            .'json="$json{\"page\":$i,\"legible\":true,\"text\":\"'.addcslashes($prefix, '"\\$`').' $abs\"}"; i=$((i+1)); done; '
            .'json="$json]}"; printf %s "$json" > "$out"; printf %s $((base+ni)) > '.$st.'; exit 0; fi; '
            .'echo X >> '.$lg.'; ';
        if (isset($options['extract'])) {
            $body .= 'printf %s '.escapeshellarg($options['extract']).' > "$out"; ';
        } else {
            $body .= ': > "$out"; ';
        }

        return self::script($body.'exit 0');
    }

    private static function script(string $body): string
    {
        $path = sys_get_temp_dir().'/fake-codex-'.bin2hex(random_bytes(6));
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0700);

        return $path;
    }
}
