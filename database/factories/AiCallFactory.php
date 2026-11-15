<?php

namespace Database\Factories;

use App\Models\AiCall;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiCall>
 */
class AiCallFactory extends Factory
{
    protected $model = AiCall::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'extraction_id' => (string) Str::uuid(), 'subject_id' => (string) Str::uuid(), 'document_id' => (string) Str::uuid(),
            'started_by' => 1, 'agent' => 'codex', 'attempt' => 1, 'status' => 'queued', 'queued_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => 'running', 'started_at' => now()]);
    }

    public function succeeded(string $model = 'default'): static
    {
        return $this->state(fn () => ['status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 1000, 'model' => $model]);
    }

    public function failed(string $code = 'unknown'): static
    {
        return $this->state(fn () => ['status' => 'failed', 'failure_code' => $code, 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 1000]);
    }

    public function queuedAt(\DateTimeInterface $at): static
    {
        return $this->state(fn () => ['queued_at' => $at]);
    }
}
