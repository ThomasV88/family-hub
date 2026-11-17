<?php

namespace App\Medical\Ai;

/**
 * Token counters of one Codex turn, read from the `turn.completed` JSONL event. Numbers only: nothing the model wrote
 * can travel in this class.
 */
final readonly class TokenUsage
{
    public function __construct(public int $input, public int $cached, public int $output, public int $reasoning) {}

    /** @param  array<string, mixed>  $event  one decoded JSONL line */
    public static function fromEvent(array $event): ?self
    {
        if (($event['type'] ?? null) !== 'turn.completed' || ! is_array($event['usage'] ?? null)) {
            return null;
        }
        $u = $event['usage'];
        $int = fn (string $key): int => is_int($u[$key] ?? null) && $u[$key] > 0 ? $u[$key] : 0;

        return new self($int('input_tokens'), $int('cached_input_tokens'), $int('output_tokens'), $int('reasoning_output_tokens'));
    }
}
