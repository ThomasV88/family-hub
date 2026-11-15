<?php

namespace App\Finance\Import;

final readonly class DecodedFile
{
    public function __construct(
        public string $text,
        public string $encoding,
        public string $suggested,
        public string $reason,
    ) {}
}
