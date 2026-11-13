<?php

namespace App\Finance\Recurring;

/**
 * Pure, integer-only matching of expected bill occurrences to booked transactions. Only same-account
 * expense or negative-transfer rows can match, so a hidden account never influences a visible bill.
 */
final class Matcher
{
    /** A text hint shorter than this (normalised) is too unspecific to pair a transaction with a bill. */
    public const MIN_HINT_LENGTH = 4;

    /** Largest amount deviation, in percent of the expected amount, that a text hint alone may auto-match. */
    public const HINT_AMOUNT_PERCENT = 50;

    public function score(Occurrence $o, MatchCandidate $c): ?int
    {
        if (! $this->eligible($o, $c)) {
            return null;
        }
        $days = abs(Days::between($o->dueDate, $c->bookedOn));
        if ($days > $o->windowDays) {
            return null;
        }

        $score = max(0, 20 - 3 * $days);
        if (abs($c->amountMinor) === $o->expectedMinor) {
            $score += 50;
        } elseif ($this->withinTolerance($o, $c)) {
            $score += 35;
        }
        if ($this->hintMatches($o, $c)) {
            $score += 30;
        }

        return $score;
    }

    public function autoAccepts(Occurrence $o, MatchCandidate $c): bool
    {
        $score = $this->score($o, $c);
        if ($score === null) {
            return false;
        }

        // A hint never matches on its own: it needs a usable length (hintMatches), the date window (score() above) and a
        // plausible amount. "Paid differently" stays reachable, but only within HINT_AMOUNT_PERCENT of the expected amount.
        return ($this->withinTolerance($o, $c) && $score >= 50) || ($this->hintMatches($o, $c) && $this->withinHintBound($o, $c));
    }

    /**
     * Greedy unique assignment with a total ordering, so the result is deterministic.
     *
     * @param  list<Occurrence>  $occurrences
     * @param  list<MatchCandidate>  $candidates
     * @param  list<string>  $rejectedPairs  'occurrenceKey|transactionId'
     * @return list<array{occurrence: string, transaction: string, score: int}>
     */
    public function assign(array $occurrences, array $candidates, array $rejectedPairs): array
    {
        $rejected = array_flip($rejectedPairs);
        $pairs = [];
        foreach ($occurrences as $o) {
            foreach ($candidates as $c) {
                if (isset($rejected[$o->key().'|'.$c->transactionId]) || ! $this->autoAccepts($o, $c)) {
                    continue;
                }
                $pairs[] = [
                    'occurrence' => $o->key(),
                    'transaction' => $c->transactionId,
                    'score' => (int) $this->score($o, $c),
                    'days' => abs(Days::between($o->dueDate, $c->bookedOn)),
                    'due' => $o->dueDate,
                ];
            }
        }

        usort($pairs, fn (array $a, array $b): int => [$b['score'], $a['days'], $a['due'], $a['transaction'], $a['occurrence']]
            <=> [$a['score'], $b['days'], $b['due'], $b['transaction'], $b['occurrence']]);

        $usedOcc = [];
        $usedTx = [];
        $out = [];
        foreach ($pairs as $p) {
            if (isset($usedOcc[$p['occurrence']]) || isset($usedTx[$p['transaction']])) {
                continue;
            }
            $usedOcc[$p['occurrence']] = true;
            $usedTx[$p['transaction']] = true;
            $out[] = ['occurrence' => $p['occurrence'], 'transaction' => $p['transaction'], 'score' => $p['score']];
        }

        return $out;
    }

    /**
     * Same-account expense or negative-transfer candidates within $maxDays of the due date, any amount.
     *
     * @param  list<MatchCandidate>  $candidates
     * @return list<MatchCandidate>
     */
    public function rankForManual(Occurrence $o, array $candidates, int $maxDays = 60): array
    {
        $rows = [];
        foreach ($candidates as $c) {
            if (! $this->eligible($o, $c)) {
                continue;
            }
            $days = abs(Days::between($o->dueDate, $c->bookedOn));
            if ($days > $maxDays) {
                continue;
            }
            $score = max(0, 20 - 3 * $days);
            if (abs($c->amountMinor) === $o->expectedMinor) {
                $score += 50;
            } elseif ($this->withinTolerance($o, $c)) {
                $score += 35;
            }
            if ($this->hintMatches($o, $c)) {
                $score += 30;
            }
            $rows[] = ['c' => $c, 'score' => $score, 'days' => $days];
        }

        usort($rows, fn (array $a, array $b): int => [$b['score'], $a['days'], $a['c']->transactionId]
            <=> [$a['score'], $b['days'], $b['c']->transactionId]);

        return array_map(fn (array $r): MatchCandidate => $r['c'], $rows);
    }

    private function eligible(Occurrence $o, MatchCandidate $c): bool
    {
        if ($c->accountId !== $o->accountId) {
            return false;
        }

        return $c->kind === 'expense' || ($c->kind === 'transfer' && $c->amountMinor < 0);
    }

    private function withinHintBound(Occurrence $o, MatchCandidate $c): bool
    {
        return abs(abs($c->amountMinor) - $o->expectedMinor) * 100 <= $o->expectedMinor * self::HINT_AMOUNT_PERCENT
            || $this->withinTolerance($o, $c);
    }

    private function withinTolerance(Occurrence $o, MatchCandidate $c): bool
    {
        return abs(abs($c->amountMinor) - $o->expectedMinor) <= $o->toleranceMinor;
    }

    private function hintMatches(Occurrence $o, MatchCandidate $c): bool
    {
        return $o->hint !== null && mb_strlen($o->hint) >= self::MIN_HINT_LENGTH && str_contains($c->normalizedDescription, $o->hint);
    }
}
