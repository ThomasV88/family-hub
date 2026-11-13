<?php

namespace App\Finance\Import;

use App\Finance\Currency;
use App\Finance\MoneyParser;
use App\Finance\TextNormalizer;

final class RowNormalizer
{
    /**
     * @param  list<list<string>>  $records
     * @return list<string>
     */
    public static function headers(array $records, ProfileSpec $p): array
    {
        if ($p->headerRow < 1 || ! isset($records[$p->headerRow - 1])) {
            return [];
        }

        return array_map(fn ($c) => trim((string) $c), $records[$p->headerRow - 1]);
    }

    /**
     * @param  list<list<string>>  $records
     * @return list<NormalizedRow>
     */
    public static function normalize(array $records, ProfileSpec $p, Currency $currency, string $balanceAsOf): array
    {
        $map = self::resolve($records, $p);
        $needed = match ($p->amountMode) {
            'single' => ['date', 'amount'],
            'split' => ['date', 'income', 'expense'],
            'indicator' => ['date', 'amount', 'indicator'],
        };
        foreach ($needed as $role) {
            if (! isset($map[$role])) {
                throw new ImportError('profile_mismatch');
            }
        }
        $maxIdx = max($map);
        $descMax = (int) config('finance.import.description_max');
        $partyMax = (int) config('finance.import.counterparty_max');
        $refMax = (int) config('finance.import.reference_max');

        $out = [];
        foreach ($records as $i => $rec) {
            $rowNo = $i + 1;
            if ($rowNo <= $p->headerRow) {
                continue;
            }
            $cell = fn (string $role): string => isset($map[$role]) ? (string) ($rec[$map[$role]] ?? '') : '';
            $party = self::clean($cell('counterparty'), $partyMax);
            $desc = self::clean($cell('description'), $descMax);
            if ($desc === '' && $party !== '') {
                $desc = self::clean($party, $descMax);
            }
            $ref = self::clean($cell('reference'), $refMax);
            $norm = TextNormalizer::normalize($desc);
            $bad = fn (string $code) => new NormalizedRow(
                $rowNo, null, null, null, $desc, $party === '' ? null : $party, $ref === '' ? null : $ref, $norm, 'invalid', $code,
            );

            if (count($rec) <= $maxIdx) {
                $out[] = $bad('columns');

                continue;
            }
            $date = DateParser::parse($cell('date'), $p->dateFormat);
            if ($date === null) {
                $out[] = $bad('date');

                continue;
            }
            $amount = self::amount($rec, $map, $p, $currency);
            if (is_string($amount)) {
                $out[] = $bad($amount);

                continue;
            }
            if (isset($map['currency'])) {
                $cur = strtoupper(trim($cell('currency')));
                if ($cur !== '' && $cur !== $currency->value) {
                    $out[] = $bad('currency');

                    continue;
                }
            }
            if ($date < $balanceAsOf) {
                $out[] = $bad('before_open');

                continue;
            }
            $out[] = new NormalizedRow(
                $rowNo, $date, $amount, $amount < 0 ? 'expense' : 'income', $desc,
                $party === '' ? null : $party, $ref === '' ? null : $ref, $norm, 'ok',
            );
        }

        return $out;
    }

    /** @return array<string, int> */
    private static function resolve(array $records, ProfileSpec $p): array
    {
        $headers = array_map(fn ($h) => mb_strtolower($h), self::headers($records, $p));
        $map = [];
        foreach ($p->columns as $role => $col) {
            $idx = null;
            if ($col['name'] !== null && $headers !== []) {
                $found = array_search(mb_strtolower(trim($col['name'])), $headers, true);
                if ($found !== false) {
                    $idx = $found;
                }
            }
            $idx ??= $col['index'];
            if ($idx !== null) {
                $map[$role] = $idx;
            }
        }

        return $map;
    }

    /** @return int|string signed minor units, or an error code */
    private static function amount(array $rec, array $map, ProfileSpec $p, Currency $c): int|string
    {
        $parse = fn (string $role) => MoneyParser::csv((string) ($rec[$map[$role]] ?? ''), $c, $p->decimalSeparator, $p->groupingSeparator);
        $blank = fn (string $role) => trim(str_replace("\u{00A0}", ' ', (string) ($rec[$map[$role]] ?? ''))) === '';

        if ($p->amountMode === 'single') {
            $v = $parse('amount');

            return ($v === null || $v === 0) ? 'amount' : $v;
        }
        if ($p->amountMode === 'split') {
            $e = $blank('expense');
            $i = $blank('income');
            if (! $e && ! $i) {
                return 'both';
            }
            if ($e && $i) {
                return 'amount';
            }
            $v = $parse($e ? 'income' : 'expense');
            if ($v === null || $v === 0) {
                return 'amount';
            }

            return $e ? abs($v) : -abs($v);
        }
        $v = $parse('amount');
        if ($v === null || $v === 0) {
            return 'amount';
        }
        $ind = mb_strtoupper(trim((string) ($rec[$map['indicator']] ?? '')));
        $upper = fn (array $l) => array_map('mb_strtoupper', $l);
        if (in_array($ind, $upper($p->indicatorExpense), true)) {
            return -abs($v);
        }
        if (in_array($ind, $upper($p->indicatorIncome), true)) {
            return abs($v);
        }

        return 'amount';
    }

    private static function clean(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        return mb_substr($s, 0, $max);
    }
}
