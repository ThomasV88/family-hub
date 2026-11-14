<?php

namespace App\Medical\Ai;

/**
 * Strict extraction of the single JSON object from an agent reply that has no schema enforcement.
 * At most one code fence is removed; there is no other repair, so anything else is invalid.
 */
final class JsonReply
{
    public static function decode(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $size = filesize($path);
        $cap = (int) config('ai.caps.result', 262144);
        if ($size === false || $size === 0 || $size > $cap) {
            return null;
        }
        $raw = file_get_contents($path, false, null, 0, $cap + 1);
        if ($raw === false) {
            return null;
        }
        $text = trim($raw);
        $lines = preg_split('/\r\n|\n/', $text) ?: [];
        if (count($lines) >= 2 && preg_match('/^```(json)?$/', rtrim($lines[0])) === 1 && rtrim($lines[count($lines) - 1]) === '```') {
            $text = trim(implode("\n", array_slice($lines, 1, -1)));
        }
        if (! str_starts_with($text, '{') || ! str_ends_with($text, '}')) {
            return null;
        }
        $data = json_decode($text, true, 8);

        return is_array($data) && ! array_is_list($data) ? $text : null;
    }
}
