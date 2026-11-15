<?php

namespace Database\Seeders\Staging;

use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\ProposalEntries;
use Carbon\CarbonImmutable;
use Database\Factories\AiCallFactory;
use Database\Factories\MedicalAiExtractionFactory;
use Database\Factories\MedicalAllergyFactory;
use Database\Factories\MedicalVisitFactory;
use Illuminate\Support\Facades\DB;

/**
 * AI proposals for the review screen, with no provider ever called: two `ready` extractions with a payload that is valid
 * against schema v1, one `failed` extraction, and the content-free call rows (ids, enums and times only) a real run
 * would have left.
 *
 * Document "blood_pdf" (adult linked to parent1): visit accepted (record is AI-sourced and Unverified), allergy accepted
 * and then verified, medication rejected, vaccination still undecided. Document "discharge_pdf" (grandparent): all four
 * entries undecided. Document "rash_jpg": a failed run. The accepted records have source "ai" like the ones
 * AiReviewQuery::accept writes (rows from the medical factories, since the arch tests keep the query classes in the medical
 * layers); no audit rows are written by hand.
 *
 * ai_calls is append-only (the runtime role cannot delete), so each reseed leaves three more call rows with ids of deleted
 * synthetic users; staging:audit accepts those and ai:prune-calls removes them after the retention period.
 */
final class AiProposalsSeeder
{
    /**
     * @param  array<string, array{id: string, name: string}>  $subjects
     * @param  array<string, array{id: string, subject_id: string, kind: string}>  $documents
     * @return array{extractions: int, calls: int, decisions: int, ai_records: int}
     */
    public function seed(StagingContext $ctx, array $subjects, array $documents): array
    {
        $counts = ['extractions' => 0, 'calls' => 0, 'decisions' => 0, 'ai_records' => 0];
        $by = $ctx->user('parent1')->id;

        if (isset($documents['blood_pdf'])) {
            $payload = $this->payload($ctx, -30, 'general_practice', [
                ['visited_on' => $ctx->day(-28), 'doctor' => 'MUDr. Hronská (demo)', 'specialty_key' => 'general_practice', 'specialty_other' => null,
                    'diagnosis' => 'Kontrola po laboratórnom vyšetrení', 'notes' => 'Cholesterol mierne zvýšený, odporúčaná zmena stravy.'],
            ], [
                ['name' => 'Atorvastatín', 'dose' => '10 mg', 'schedule' => 'denne večer', 'started_on' => $ctx->day(-28), 'ended_on' => null, 'notes' => null],
            ], [
                ['substance' => 'Kontrastná látka s jódom', 'reaction' => 'Svrbenie a začervenanie kože', 'severity' => 'moderate'],
            ], [
                ['vaccine' => 'Kliešťová encefalitída', 'given_on' => $ctx->day(-27), 'next_due_on' => $ctx->day(338), 'notes' => null],
            ]);
            $extraction = $this->extraction($ctx, $documents['blood_pdf'], $payload, $by, 'ready');
            $counts['extractions']++;
            $counts['calls'] += $this->call($extraction, $by, 'succeeded', null);

            $subjectId = $subjects['adult_linked']['id'];
            $visit = MedicalVisitFactory::new()->create($payload['visits'][0] + [
                'subject_id' => $subjectId, 'source' => 'ai', 'verified_at' => null, 'verified_by' => null, 'created_by' => $by,
            ]);
            $this->decide($extraction, 'visit-0', 'accepted', 'visit', (string) $visit->getKey(), $by);
            DB::table('medical_documents')->where('id', $documents['blood_pdf']['id'])->update(['visit_id' => $visit->getKey()]);

            // accepted, then marked as verified by the reviewer
            $allergy = MedicalAllergyFactory::new()->create($payload['allergies'][0] + [
                'subject_id' => $subjectId, 'source' => 'ai', 'verified_at' => $ctx->at(-1, 9), 'verified_by' => $by, 'created_by' => $by,
            ]);
            $this->decide($extraction, 'allergy-0', 'accepted', 'allergy', (string) $allergy->getKey(), $by);

            $this->decide($extraction, 'medication-0', 'rejected', null, null, $by);
            $counts['decisions'] += 3;
            $counts['ai_records'] += 2;
        }

        if (isset($documents['discharge_pdf'])) {
            $payload = $this->payload($ctx, -18, 'geriatrics', [
                ['visited_on' => $ctx->day(-19), 'doctor' => 'MUDr. Nowak (demo)', 'specialty_key' => 'geriatrics', 'specialty_other' => null,
                    'diagnosis' => 'Ročná kontrola', 'notes' => 'Stav stabilný, doplniť očkovanie proti pneumokoku.'],
            ], [
                ['name' => 'Atorvastatín', 'dose' => '20 mg', 'schedule' => 'denne večer', 'started_on' => $ctx->day(-19), 'ended_on' => null, 'notes' => 'Nový liek po kontrole lipidov.'],
            ], [
                ['substance' => 'Jód (kontrastná látka)', 'reaction' => null, 'severity' => 'unknown'],
            ], [
                ['vaccine' => 'Pneumokok (PCV20)', 'given_on' => $ctx->day(-18), 'next_due_on' => null, 'notes' => 'Podaná na ambulancii.'],
            ]);
            $extraction = $this->extraction($ctx, $documents['discharge_pdf'], $payload, $by, 'ready');
            $counts['extractions']++;
            $counts['calls'] += $this->call($extraction, $by, 'succeeded', null);
        }

        if (isset($documents['rash_jpg'])) {
            $extraction = $this->extraction($ctx, $documents['rash_jpg'], null, $by, 'failed');
            $counts['extractions']++;
            $counts['calls'] += $this->call($extraction, $by, 'failed', 'invalid_output');
        }

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $visits
     * @param  list<array<string, mixed>>  $medications
     * @param  list<array<string, mixed>>  $allergies
     * @param  list<array<string, mixed>>  $vaccinations
     * @return array<string, mixed>
     */
    private function payload(StagingContext $ctx, int $dayOffset, string $specialty, array $visits, array $medications, array $allergies, array $vaccinations): array
    {
        return [
            'schema_version' => ExtractionSchema::SCHEMA_VERSION,
            'document_date' => $ctx->day($dayOffset),
            'specialty_key' => $specialty,
            'visits' => $visits,
            'medications' => $medications,
            'allergies' => $allergies,
            'vaccinations' => $vaccinations,
        ];
    }

    /**
     * @param  array{id: string, subject_id: string, kind: string}  $document
     * @param  array<string, mixed>|null  $payload
     * @return array{id: string, subject_id: string, document_id: string, queued_at: CarbonImmutable, started_at: CarbonImmutable, finished_at: CarbonImmutable}
     */
    private function extraction(StagingContext $ctx, array $document, ?array $payload, int $by, string $status): array
    {
        $queued = $ctx->at(-2, 10, 0);
        $row = MedicalAiExtractionFactory::new()->create([
            'subject_id' => $document['subject_id'],
            'document_id' => $document['id'],
            'status' => $status,
            'failure_code' => $status === 'failed' ? 'invalid_output' : null,
            'agent' => 'codex',
            'model' => $status === 'ready' ? 'default' : null,
            'schema_version' => ExtractionSchema::SCHEMA_VERSION,
            'prompt_version' => ExtractionSchema::PROMPT_VERSION,
            'started_by' => $by,
            'payload' => $payload,
            'entry_count' => $payload === null ? null : count(ProposalEntries::keys($payload)),
            'pages_read' => $document['kind'] === 'pdf' ? 2 : 1,
            'pages_total' => $document['kind'] === 'pdf' ? 2 : 1,
            'queued_at' => $queued,
            'started_at' => $queued->addSeconds(4),
            'finished_at' => $queued->addSeconds(41),
            'created_at' => $queued,
            'updated_at' => $queued->addSeconds(41),
        ]);

        return [
            'id' => (string) $row->getKey(), 'subject_id' => $document['subject_id'], 'document_id' => $document['id'],
            'queued_at' => $queued, 'started_at' => $queued->addSeconds(4), 'finished_at' => $queued->addSeconds(41),
        ];
    }

    /** @param  array{id: string, subject_id: string, document_id: string, queued_at: CarbonImmutable, started_at: CarbonImmutable, finished_at: CarbonImmutable}  $extraction */
    private function call(array $extraction, int $by, string $status, ?string $failureCode): int
    {
        AiCallFactory::new()->create([
            'extraction_id' => $extraction['id'], 'subject_id' => $extraction['subject_id'], 'document_id' => $extraction['document_id'],
            'started_by' => $by, 'agent' => 'codex', 'model' => $status === 'succeeded' ? 'default' : null, 'attempt' => 1,
            'status' => $status, 'failure_code' => $failureCode,
            'queued_at' => $extraction['queued_at'], 'started_at' => $extraction['started_at'], 'finished_at' => $extraction['finished_at'],
            'duration_ms' => 37000,
        ]);

        return 1;
    }

    /** @param  array{id: string, finished_at: CarbonImmutable}  $extraction */
    private function decide(array $extraction, string $entryKey, string $decision, ?string $type, ?string $recordId, int $by): void
    {
        DB::table('medical_ai_decisions')->insert([
            'extraction_id' => $extraction['id'], 'entry_key' => $entryKey, 'decision' => $decision, 'record_type' => $type,
            'record_id' => $recordId, 'edited' => false, 'decided_by' => $by, 'decided_at' => $extraction['finished_at']->addMinutes(30),
        ]);
    }
}
