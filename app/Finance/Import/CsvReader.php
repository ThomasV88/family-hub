<?php

namespace App\Finance\Import;

final class CsvReader
{
    private const PREAMBLE_ALLOWANCE = 50;

    public static function guessDelimiter(string $text): string
    {
        $candidates = config('finance.import.delimiters');
        $inQuotes = false;
        $len = strlen($text);
        $perLine = [];
        $reset = fn () => array_fill_keys($candidates, 0);
        $current = $reset();
        $nonEmpty = 0;
        $hasContent = false;
        for ($i = 0; $i < $len && $nonEmpty < 20; $i++) {
            $c = $text[$i];
            if ($c === '"') {
                $inQuotes = ! $inQuotes;
                $hasContent = true;

                continue;
            }
            if ($c === "\n" && ! $inQuotes) {
                if ($hasContent) {
                    $perLine[] = $current;
                    $nonEmpty++;
                }
                $current = $reset();
                $hasContent = false;

                continue;
            }
            if ($inQuotes) {
                continue;
            }
            if (isset($current[$c])) {
                $current[$c]++;
            }
            if (trim($c) !== '' || $c === "\t") {
                $hasContent = true;
            }
        }
        if ($hasContent && $nonEmpty < 20) {
            $perLine[] = $current;
        }

        // Score: lines agreeing on a delimiter count x that count, so a decimal comma (1 per line)
        // never beats a real column separator (several per line).
        $best = $candidates[0];
        $bestScore = 0;
        foreach ($candidates as $cand) {
            $freq = [];
            foreach ($perLine as $l) {
                if ($l[$cand] >= 1) {
                    $freq[$l[$cand]] = ($freq[$l[$cand]] ?? 0) + 1;
                }
            }
            foreach ($freq as $count => $lines) {
                if ($lines * $count > $bestScore) {
                    $bestScore = $lines * $count;
                    $best = $cand;
                }
            }
        }

        return $best;
    }

    /** @return list<list<string>> */
    public static function rows(string $text, string $delimiter, int $maxRows): array
    {
        $h = fopen('php://temp/maxmemory:2097152', 'w+');
        fwrite($h, $text);
        rewind($h);
        $rows = [];
        try {
            while (($row = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $rows[] = array_map(fn ($v) => (string) $v, $row);
                if (count($rows) > $maxRows + self::PREAMBLE_ALLOWANCE) {
                    throw new ImportError('too_many_rows');
                }
            }
        } finally {
            fclose($h);
        }

        return $rows;
    }
}
