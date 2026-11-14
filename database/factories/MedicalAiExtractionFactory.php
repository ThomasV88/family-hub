<?php

namespace Database\Factories;

use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows only. Pass subject_id and document_id, or use forDocument(). Default state is queued.
 *
 * @extends Factory<MedicalAiExtraction>
 */
class MedicalAiExtractionFactory extends Factory
{
    protected $model = MedicalAiExtraction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['status' => 'queued', 'agent' => 'codex', 'schema_version' => 1, 'prompt_version' => 1, 'started_by' => 1];
    }

    public function forDocument(MedicalDocument $document): static
    {
        return $this->state(fn () => ['document_id' => $document->id, 'subject_id' => $document->subject_id]);
    }

    public function queued(): static
    {
        return $this->state(fn () => ['status' => 'queued']);
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => 'running', 'started_at' => now()]);
    }

    /** @param  array<string, mixed>  $payload */
    public function ready(array $payload = ['entries' => []], int $entryCount = 0): static
    {
        return $this->state(fn () => ['status' => 'ready', 'payload' => $payload, 'entry_count' => $entryCount,
            'model' => 'default', 'started_at' => now(), 'finished_at' => now()]);
    }

    public function failed(string $code = 'unknown'): static
    {
        return $this->state(fn () => ['status' => 'failed', 'failure_code' => $code, 'finished_at' => now()]);
    }

    public function cancelled(string $reason = 'user'): static
    {
        return $this->state(fn () => ['status' => 'cancelled', 'cancel_reason' => $reason, 'finished_at' => now()]);
    }

    /** @param  array<string, mixed>  $payload */
    public function superseded(array $payload = ['entries' => []]): static
    {
        return $this->state(fn () => ['status' => 'superseded', 'payload' => $payload, 'finished_at' => now()]);
    }
}
