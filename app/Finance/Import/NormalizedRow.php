<?php

namespace App\Finance\Import;

final readonly class NormalizedRow
{
    public function __construct(
        public int $rowNo,
        public ?string $bookedOn,
        public ?int $amountMinor,
        public ?string $kind,
        public string $description,
        public ?string $counterparty,
        public ?string $reference,
        public string $normalizedDescription,
        public string $state,
        public ?string $errorCode = null,
        public ?string $importHash = null,
    ) {}

    public function withHash(string $hash): self
    {
        return new self(
            $this->rowNo, $this->bookedOn, $this->amountMinor, $this->kind, $this->description,
            $this->counterparty, $this->reference, $this->normalizedDescription, $this->state,
            $this->errorCode, $hash,
        );
    }
}
