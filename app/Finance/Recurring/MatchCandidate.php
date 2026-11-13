<?php

namespace App\Finance\Recurring;

/** A booked transaction offered to the matcher. amountMinor is signed as stored; kind is income|expense|transfer. */
final readonly class MatchCandidate
{
    public function __construct(
        public string $transactionId,
        public string $accountId,
        public string $bookedOn,
        public int $amountMinor,
        public string $kind,
        public string $normalizedDescription,
    ) {}
}
