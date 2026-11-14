<?php

namespace App\Medical\Ai\Agents;

use App\Medical\Ai\FailureCode;

/** The result of one agent run: a validated payload or a content-free failure code, never CLI text. */
final readonly class AgentOutcome
{
    /** @param array<string, mixed>|null $payload */
    public function __construct(
        public bool $ok,
        public ?array $payload,
        public ?int $entryCount,
        public ?FailureCode $failure,
        public bool $aborted,
        public int $durationMs,
        public string $model,
    ) {}
}
