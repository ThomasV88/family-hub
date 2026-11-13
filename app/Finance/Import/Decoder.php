<?php

namespace App\Finance\Import;

final class Decoder
{
    /** @return array{encoding: string, reason: string} */
    public static function suggest(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return ['encoding' => 'UTF-8', 'reason' => 'bom'];
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return ['encoding' => 'UTF-16LE', 'reason' => 'bom'];
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return ['encoding' => 'UTF-16BE', 'reason' => 'bom'];
        }
        if (preg_match('/[\x80-\xFF]/', $bytes) === 1 && mb_check_encoding($bytes, 'UTF-8')) {
            return ['encoding' => 'UTF-8', 'reason' => 'utf8_valid'];
        }
        if (preg_match('/[\x8A\x8D\x8E\x9A\x9D\x9E]/', $bytes) === 1) {
            return ['encoding' => 'WINDOWS-1250', 'reason' => 'cp1250_bytes'];
        }

        return ['encoding' => 'WINDOWS-1250', 'reason' => 'default'];
    }

    public static function decode(string $bytes, ?string $encoding = null): DecodedFile
    {
        if (strlen($bytes) > (int) config('finance.import.max_bytes')) {
            throw new ImportError('too_large');
        }
        if (trim($bytes) === '') {
            throw new ImportError('empty');
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            throw new ImportError('binary');
        }

        $suggested = self::suggest($bytes);
        $encoding = $encoding === null ? $suggested['encoding'] : strtoupper($encoding);
        if (! in_array($encoding, config('finance.import.encodings'), true)) {
            throw new ImportError('decode_failed');
        }

        $utf16 = str_starts_with($encoding, 'UTF-16');
        if (! $utf16 && str_contains($bytes, "\0")) {
            throw new ImportError('binary');
        }

        if ($encoding === 'UTF-8') {
            if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
                $bytes = substr($bytes, 3);
            }
            if (! mb_check_encoding($bytes, 'UTF-8')) {
                throw new ImportError('decode_failed');
            }
            $text = $bytes;
        } else {
            if ($utf16) {
                $bytes = preg_replace('/^(\xFF\xFE|\xFE\xFF)/', '', $bytes) ?? $bytes;
                if (strlen($bytes) % 2 !== 0) {
                    throw new ImportError('decode_failed');
                }
            }
            $failed = false;
            set_error_handler(function () use (&$failed): bool {
                $failed = true;

                return true;
            });
            try {
                $text = iconv($encoding, 'UTF-8', $bytes);
            } finally {
                restore_error_handler();
            }
            if ($failed || $text === false) {
                throw new ImportError('decode_failed');
            }
        }

        // A BOM is one character; ltrim() with it would treat it as a byte set and eat the lead byte of e.g. U+FF21.
        $text = preg_replace('/^\x{FEFF}+/u', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if ($text === null) {
            throw new ImportError('decode_failed');
        }
        if (trim($text) === '') {
            throw new ImportError('empty');
        }

        return new DecodedFile($text, $encoding, $suggested['encoding'], $suggested['reason']);
    }
}
