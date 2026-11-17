<?php

namespace App\Medical\Ai\Eval;

use App\Medical\Documents\Text\SearchTerms;

/** Micro precision, recall and F1 of extracted entries over their key fields. */
final class EntryScorer
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $predicted
     * @return array{precision: float, recall: float, f1: float, tp: int, fp: int, fn: int}
     */
    public static function score(array $expected, array $predicted): array
    {
        $e = self::keys($expected);
        $p = self::keys($predicted);
        $tp = 0;
        foreach ($p as $key => $count) {
            $tp += min($count, $e[$key] ?? 0);
        }
        $fp = array_sum($p) - $tp;
        $fn = array_sum($e) - $tp;

        if ($tp + $fp + $fn === 0) {
            return ['precision' => 1.0, 'recall' => 1.0, 'f1' => 1.0, 'tp' => 0, 'fp' => 0, 'fn' => 0];
        }
        $precision = $tp + $fp === 0 ? 0.0 : $tp / ($tp + $fp) + 0.0;
        $recall = $tp + $fn === 0 ? 0.0 : $tp / ($tp + $fn) + 0.0;
        $f1 = $precision + $recall === 0.0 ? 0.0 : 2 * $precision * $recall / ($precision + $recall);

        return ['precision' => $precision, 'recall' => $recall, 'f1' => $f1, 'tp' => $tp, 'fp' => $fp, 'fn' => $fn];
    }

    /** @return array<string, int> multiset of key strings */
    private static function keys(array $d): array
    {
        $out = [];
        $add = function (string $kind, array $fields) use (&$out): void {
            $key = $kind.'|'.implode('|', array_map(fn ($v) => self::norm($v), $fields));
            $out[$key] = ($out[$key] ?? 0) + 1;
        };
        foreach ((array) ($d['medications'] ?? []) as $r) {
            $add('med', [$r['name'] ?? null, $r['dose'] ?? null]);
        }
        foreach ((array) ($d['allergies'] ?? []) as $r) {
            $add('allergy', [$r['substance'] ?? null]);
        }
        foreach ((array) ($d['vaccinations'] ?? []) as $r) {
            $add('vaccine', [$r['vaccine'] ?? null, $r['given_on'] ?? null]);
        }
        foreach ((array) ($d['visits'] ?? []) as $r) {
            $add('visit', [$r['visited_on'] ?? null, $r['doctor'] ?? null, $r['diagnosis'] ?? null]);
        }
        if (($d['document_date'] ?? null) !== null) {
            $add('date', [$d['document_date']]);
        }
        if (($d['specialty_key'] ?? null) !== null) {
            $add('specialty', [$d['specialty_key']]);
        }

        return $out;
    }

    private static function norm(mixed $v): string
    {
        if (! is_string($v)) {
            return '';
        }

        // All whitespace goes: "500 mg" and "500mg" are the same dose.
        return (string) preg_replace('/\s+|[.,;]+$/u', '', SearchTerms::fold($v));
    }
}
