<?php

namespace App\Finance\Import;

final class ImportHasher
{
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
            if ($row->reference !== null && $row->reference !== '') {
                $out[] = $row->withHash(hash('sha256', $accountId.'|ref|'.$row->reference));

                continue;
            }
            $key = $row->bookedOn.'|'.$row->amountMinor.'|'.$row->normalizedDescription;
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $out[] = $row->withHash(hash('sha256', $accountId.'|'.$key.'|'.$seen[$key]));
        }

        return $out;
    }
}
