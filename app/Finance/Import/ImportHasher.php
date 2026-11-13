<?php

namespace App\Finance\Import;

final class ImportHasher
{
    /** Hash algorithm version (no live data existed when v2 replaced v1). */
    private const VERSION = 'v2';

    /**
     * @param  list<NormalizedRow>  $rows
     * @return list<NormalizedRow>
     */
    public static function apply(string $accountId, array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if ($row->state !== 'ok') {
                $out[] = $row;

                continue;
            }
            // v2: identity is account + date + amount + normalized description; the bank reference is only an
            // additional component (references recycle and repeat within a file). Exact repeats get an ordinal.
            $key = $row->bookedOn.'|'.$row->amountMinor.'|'.$row->normalizedDescription;
            if ($row->reference !== null && $row->reference !== '') {
                $key .= '|ref:'.$row->reference;
            }
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $out[] = $row->withHash(hash('sha256', self::VERSION.'|'.$accountId.'|'.$key.'|'.$seen[$key]));
        }

        return $out;
    }
}
