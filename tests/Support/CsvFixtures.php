<?php

namespace Tests\Support;

use InvalidArgumentException;

/**
 * Synthetic bank-statement bytes generated in code. Invented IBANs, merchants and amounts;
 * no layout claims to match a real bank. Deterministic for the same options.
 */
final class CsvFixtures
{
    private const BASE_ROWS = [
        ['2026-01-05', 'Zaplatené nájomné', 'Ľudovít Štúr', -45000],
        ['2026-01-07', 'Škôlka január', 'Škôlka Slniečko', -12050],
        ['2026-01-10', 'Výplata', 'Zamestnávateľ s.r.o.', 250000],
        ['2026-01-12', 'Nákup Żabka Łódź', 'Żabka Łódź', -1234],
        ['2026-01-15', 'Prevod od rodiny', 'Rodina', 123456],
    ];

    private const PREAMBLE = [
        ['Výpis z účtu', 'SK00 0000 0000 0000 0000 0001'],
        ['Majiteľ', 'Ľudovít Štúr'],
        ['Obdobie', '01.01.2026 - 31.01.2026'],
        ['Mena účtu', 'EUR'],
    ];

    /** Options of the committed fixture files (the committed bytes must equal the generated ones). */
    public static function committed(): array
    {
        return [
            'statement-sk-windows1250.csv' => [
                'encoding' => 'WINDOWS-1250', 'delimiter' => ';', 'newline' => 'CRLF',
                'preamble' => 3, 'footer' => true, 'duplicates' => true,
            ],
            'statement-pl-iso88592.csv' => [
                'encoding' => 'ISO-8859-2', 'delimiter' => ';', 'newline' => 'LF',
                'date_format' => 'Y-m-d', 'decimal' => '.', 'grouping' => '', 'quoted_rows' => true,
            ],
            'statement-utf8-bom.csv' => [
                'encoding' => 'UTF-8-BOM', 'delimiter' => ',', 'newline' => 'CRLF',
                'amount_mode' => 'split', 'time' => true, 'date_format' => 'd/m/Y',
            ],
        ];
    }

    /**
     * Options: delimiter, encoding (UTF-8, UTF-8-BOM, UTF-16LE-BOM, WINDOWS-1250, ISO-8859-2, CP852),
     * newline (CRLF|LF|CR), decimal, grouping, date_format, time, preamble (lines), footer, amount_mode
     * (single|split|indicator), negative (minus|trailing|parens, single mode), quoted_rows, trailing_blank,
     * duplicates, wrong_currency, invalid_date, rows (generate N plain rows instead of the base rows).
     */
    public static function statement(array $opts = []): string
    {
        $o = $opts + [
            'delimiter' => ';', 'encoding' => 'WINDOWS-1250', 'newline' => 'CRLF', 'decimal' => ',',
            'grouping' => ' ', 'date_format' => 'd.m.Y', 'time' => false, 'preamble' => 0, 'footer' => false,
            'amount_mode' => 'single', 'negative' => 'minus', 'quoted_rows' => false, 'trailing_blank' => 0,
            'duplicates' => false, 'wrong_currency' => false, 'invalid_date' => false, 'rows' => null,
        ];
        $nl = match ($o['newline']) {
            'CRLF' => "\r\n", 'LF' => "\n", 'CR' => "\r",
            default => throw new InvalidArgumentException('newline'),
        };

        $data = [];
        if ($o['rows'] !== null) {
            for ($i = 1; $i <= $o['rows']; $i++) {
                $data[] = ['2026-01-'.sprintf('%02d', ($i % 28) + 1), 'Platba '.$i, 'Obchod '.$i, -100 - $i, 'EUR'];
            }
        } else {
            foreach (self::BASE_ROWS as $r) {
                $data[] = [...$r, 'EUR'];
            }
            if ($o['duplicates']) {
                $data[] = ['2026-01-20', 'Káva', 'Kaviareň', -250, 'EUR'];
                $data[] = ['2026-01-20', 'Káva', 'Kaviareň', -250, 'EUR'];
            }
            if ($o['quoted_rows']) {
                $data[] = ['2026-01-22', "Poplatok; \"špeciál\"\nriadok dva", 'Banka, a.s.', -150, 'EUR'];
            }
            if ($o['wrong_currency']) {
                $data[] = ['2026-01-25', 'Platba kartou', 'Obchod USA', -1999, 'USD'];
            }
        }

        $lines = [];
        for ($i = 0; $i < $o['preamble']; $i++) {
            $lines[] = self::line(self::PREAMBLE[$i % count(self::PREAMBLE)], $o['delimiter']);
        }
        $header = match ($o['amount_mode']) {
            'single' => ['Dátum', 'Popis', 'Protistrana', 'Suma', 'Mena'],
            'split' => ['Dátum', 'Popis', 'Protistrana', 'Ťarcha', 'Kredit', 'Mena'],
            'indicator' => ['Dátum', 'Popis', 'Protistrana', 'Suma', 'Smer', 'Mena'],
            default => throw new InvalidArgumentException('amount_mode'),
        };
        $lines[] = self::line($header, $o['delimiter']);

        $time = $o['time'] ? ' 13:45' : '';
        foreach ($data as [$date, $desc, $party, $minor, $cur]) {
            $d = self::date($date, $o['date_format']).$time;
            $row = match ($o['amount_mode']) {
                'single' => [$d, $desc, $party, self::amount($minor, $o), $cur],
                'split' => [$d, $desc, $party, $minor < 0 ? self::amount(-$minor, $o, false) : '', $minor > 0 ? self::amount($minor, $o, false) : '', $cur],
                'indicator' => [$d, $desc, $party, self::amount(abs($minor), $o, false), $minor < 0 ? 'MD' : 'D', $cur],
            };
            $lines[] = self::line($row, $o['delimiter']);
        }
        if ($o['invalid_date']) {
            $row = [self::date('2026-02-31', $o['date_format']), 'Neplatný dátum', 'Nikto', self::amount(-100, $o, false), 'EUR'];
            $lines[] = self::line($row, $o['delimiter']);
        }
        if ($o['footer']) {
            $lines[] = self::line(['Počiatočný zostatok', self::amount(100000, $o, false)], $o['delimiter']);
            $lines[] = self::line(['Súčet', self::amount(1, $o, false)], $o['delimiter']);
        }
        for ($i = 0; $i < $o['trailing_blank']; $i++) {
            $lines[] = '';
        }

        return self::encode(implode($nl, $lines).$nl, $o['encoding']);
    }

    private static function encode(string $utf8, string $encoding): string
    {
        $conv = fn (string $to) => (string) iconv('UTF-8', $to, $utf8);

        return match ($encoding) {
            'UTF-8' => $utf8,
            'UTF-8-BOM' => "\xEF\xBB\xBF".$utf8,
            'UTF-16LE-BOM' => "\xFF\xFE".$conv('UTF-16LE'),
            'WINDOWS-1250', 'ISO-8859-2', 'CP852' => $conv($encoding),
            default => throw new InvalidArgumentException('encoding'),
        };
    }

    private static function line(array $cells, string $delimiter): string
    {
        return implode($delimiter, array_map(function (string $c) use ($delimiter): string {
            if (str_contains($c, $delimiter) || str_contains($c, '"') || str_contains($c, "\n")) {
                return '"'.str_replace('"', '""', $c).'"';
            }

            return $c;
        }, $cells));
    }

    private static function date(string $ymd, string $format): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));

        return strtr($format, [
            'd' => sprintf('%02d', $d), 'j' => (string) $d, 'm' => sprintf('%02d', $m), 'n' => (string) $m,
            'Y' => (string) $y, 'y' => sprintf('%02d', $y % 100),
        ]);
    }

    private static function amount(int $minor, array $o, bool $sign = true): string
    {
        $abs = abs($minor);
        $whole = (string) intdiv($abs, 100);
        $frac = sprintf('%02d', $abs % 100);
        if ($o['grouping'] !== '') {
            $whole = strrev(implode($o['grouping'], str_split(strrev($whole), 3)));
        }
        $text = $whole.$o['decimal'].$frac;
        if (! $sign || $minor >= 0) {
            return $text;
        }

        return match ($o['negative']) {
            'trailing' => $text.'-',
            'parens' => '('.$text.')',
            default => '-'.$text,
        };
    }
}
