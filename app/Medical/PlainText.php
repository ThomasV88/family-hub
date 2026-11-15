<?php

namespace App\Medical;

/** Strips control and invisible formatting (bidi, zero-width) characters from untrusted text. */
final class PlainText
{
    /** Newlines survive only when asked for (default). Null stays null. */
    public static function clean(?string $text, bool $keepNewlines = true): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\t", ' ', $text);
        if (! $keepNewlines) {
            $text = str_replace("\n", ' ', $text);
        }

        // Cc (control, minus the newline kept above), Cf (bidi marks, zero-width) and line/paragraph separators.
        return preg_replace($keepNewlines ? '/[^\P{Cc}\n]|[\p{Cf}\x{2028}\x{2029}]/u' : '/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', '', $text) ?? '';
    }
}
