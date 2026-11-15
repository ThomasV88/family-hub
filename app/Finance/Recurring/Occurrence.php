<?php

namespace App\Finance\Recurring;

/** One due date of a recurring bill. Amounts are integer minor units; expectedMinor is the expense magnitude. */
final readonly class Occurrence
{
    public function __construct(
        public string $recurringId,
        public string $dueDate,
        public int $expectedMinor,
        public int $toleranceMinor,
        public int $windowDays,
        public string $accountId,
        public ?string $hint = null,
    ) {}

    public function key(): string
    {
        return $this->recurringId.'.'.$this->dueDate;
    }
}
