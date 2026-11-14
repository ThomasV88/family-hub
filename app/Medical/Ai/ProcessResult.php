<?php

namespace App\Medical\Ai;

/**
 * Outcome of a supervised codex run: counters and flags only.
 * Never add output text here; reportedModel is a validated identifier, not output.
 */
final readonly class ProcessResult
{
    public function __construct(
        public ?int $exitCode,
        public bool $timedOut,
        public bool $idleTimedOut,
        public bool $aborted,
        public bool $outputCapped,
        public bool $authSignature,
        public int $durationMs,
        public ?string $reportedModel = null,
    ) {}
}
