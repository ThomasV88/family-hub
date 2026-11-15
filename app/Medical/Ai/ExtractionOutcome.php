<?php

namespace App\Medical\Ai;

final readonly class ExtractionOutcome
{
    /** @param array<string, mixed>|null $payload */
    private function __construct(
        public bool $ok,
        public ?array $payload,
        public ?int $entryCount,
        public ?FailureCode $failure,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function success(array $payload, int $entryCount): self
    {
        return new self(true, $payload, $entryCount, null);
    }

    public static function failed(FailureCode $failure): self
    {
        return new self(false, null, null, $failure);
    }
}
