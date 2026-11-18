<?php

namespace Database\Seeders\Staging;

use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\ProposalEntries;
use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Queries\UploadBatchQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\AiCallFactory;
use Database\Factories\MedicalAiExtractionFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One unfinished upload batch (Phase 15) for the consenting demo person "adult_linked" (parent1's own record), written
 * through the production services: the batch and its documents through UploadBatchQuery and the document store, the
 * member's choices through saveChoices and the finished item through continue (so the accepted record and its decision row
 * are the ones a real Continue writes). Only the AI runs are rows, as AiProposalsSeeder writes them, because no provider
 * is ever called.
 *
 * Items in upload order: 1 done (continued, one accepted allergy), 2 ready for review (four findings, saved choices incl.
 * one edited value), 3 failed (unreadable_document), 4 paused for quota until a time ahead, 5 waiting (not sent). The paused
 * run is active, so the batch tick (one document at a time) leaves item 5 waiting and never starts a real run on staging.
 * The seeded findings do not match the pictures (generated shapes with a line of text).
 *
 * The batch expires 30 days after seeding; a reseed refreshes it. Demo login: parent1 (sk_sk.1).
 */
final class UploadBatchSeeder
{
    /**
     * @param  array<string, array{id: string, name: string}>  $subjects
     * @return array{batches: int, items: int, extractions: int}
     */
    public function seed(StagingContext $ctx, array $subjects): array
    {
        $counts = ['batches' => 0, 'items' => 0, 'extractions' => 0];
        if (! isset($subjects['adult_linked'])) {
            return $counts;
        }
        $parent = $ctx->user('parent1');
        $viewer = Viewer::for($parent);

        // The seed writes no audit rows by hand or by service (a demo needs none); the services get a logger that records nothing.
        $silent = new class extends AuditLogger
        {
            public function record(AuditEvent $event, ?User $actor, array $meta = [], ?User $target = null, ?Module $module = null): void {}
        };
        app()->instance(AuditLogger::class, $silent);
        try {
            return $this->build($ctx, $subjects, $parent, $viewer);
        } finally {
            app()->forgetInstance(AuditLogger::class);
        }
    }

    /**
     * @param  array<string, array{id: string, name: string}>  $subjects
     * @return array{batches: int, items: int, extractions: int}
     */
    private function build(StagingContext $ctx, array $subjects, User $parent, Viewer $viewer): array
    {
        $counts = ['batches' => 0, 'items' => 0, 'extractions' => 0];
        $subject = MedicalSubject::query()->findOrFail($subjects['adult_linked']['id']);
        $subjectId = (string) $subject->getKey();
        $queries = app(UploadBatchQuery::class);
        $store = app(MedicalDocumentStore::class);

        $batchId = $this->batch($queries, $viewer, $subject, $parent->id);
        if ($batchId === null) {
            return $counts;
        }
        $counts['batches'] = 1;

        $titles = [
            1 => 'Kontrola alergie, sken 1 (demo)',
            2 => 'Správa z ambulancie, sken 2 (demo)',
            3 => 'Rozmazaná fotka, sken 3 (demo)',
            4 => 'Výsledky vyšetrenia, sken 4 (demo)',
            5 => 'Očkovanie, sken 5 (demo)',
        ];
        $items = [];
        foreach ($titles as $n => $title) {
            try {
                $stored = $store->put($subjectId, $this->image("Batch scan {$n}", $n));
                $added = $queries->addDocument($viewer, $subject, $batchId, $stored, $title, $parent);
            } catch (DocumentRejected $e) {
                Log::warning('staging seed: batch document skipped', ['position' => $n, 'reason' => $e->getMessage()]);

                continue;
            }
            if ($added !== null) {
                $items[$n] = $added;
                $counts['items']++;
            }
        }

        // 1: ready, one finding approved, then Continue (the document becomes an ordinary one).
        if (isset($items[1])) {
            $payload = $this->payload($ctx, -3, [], [], [
                ['substance' => 'Penicilín (demo)', 'reaction' => 'Vyrážka', 'severity' => 'moderate'],
            ], []);
            $this->run($ctx, $subjectId, $batchId, $items[1], 'ready', $payload, $parent->id);
            $counts['extractions']++;
            $queries->saveChoices($viewer, $subject, $batchId, $items[1]['itemId'], ['allergy-0' => ['decision' => 'approve']]);
            $queries->continue($viewer, $subject, $batchId, $items[1]['itemId'], $parent);
        }

        // 2: ready for review; one approved with an edited value, one rejected, two undecided.
        if (isset($items[2])) {
            $payload = $this->payload($ctx, -5, [
                ['visited_on' => $ctx->day(-6), 'doctor' => 'MUDr. Hronská (demo)', 'specialty_key' => 'general_practice', 'specialty_other' => null,
                    'diagnosis' => 'Kontrola po prechladnutí', 'notes' => 'Odporúčaný pokoj a tekutiny.'],
            ], [
                ['name' => 'Paracetamol', 'dose' => '500 mg', 'schedule' => 'podľa potreby', 'started_on' => $ctx->day(-6), 'ended_on' => null, 'notes' => null],
            ], [
                ['substance' => 'Peľ brezy', 'reaction' => 'Kýchanie', 'severity' => 'mild'],
            ], [
                ['vaccine' => 'Chrípka', 'given_on' => $ctx->day(-6), 'next_due_on' => null, 'notes' => null],
            ]);
            $this->run($ctx, $subjectId, $batchId, $items[2], 'ready', $payload, $parent->id);
            $counts['extractions']++;
            $queries->saveChoices($viewer, $subject, $batchId, $items[2]['itemId'], [
                'visit-0' => ['decision' => 'approve', 'values' => ['diagnosis' => 'Kontrola po prechladnutí, upravené (demo)']],
                'medication-0' => ['decision' => 'reject'],
            ]);
        }

        // 3: the AI could not read it.
        if (isset($items[3])) {
            $this->run($ctx, $subjectId, $batchId, $items[3], 'failed', null, $parent->id);
            $counts['extractions']++;
        }

        // 4: paused for AI quota until a time ahead.
        if (isset($items[4])) {
            $this->run($ctx, $subjectId, $batchId, $items[4], 'waiting', null, $parent->id);
            $counts['extractions']++;
        }

        // 5 stays pending (waiting for its turn).

        // Older than the medical delete window, like every other seeded record (a delete would archive, not remove).
        $old = $ctx->at(-4, 10);
        DB::table('medical_documents')->where('subject_id', $subjectId)->whereIn('id', array_column($items, 'documentId'))->update(['created_at' => $old, 'updated_at' => $old]);
        DB::table('medical_allergies')->where('subject_id', $subjectId)->where('substance', 'Penicilín (demo)')->update(['created_at' => $old, 'updated_at' => $old]);

        // The cursor: the first unfinished item is the ready one.
        if (isset($items[2])) {
            DB::table('medical_upload_batches')->where('id', $batchId)->update(['current_item_id' => $items[2]['itemId']]);
        }

        return $counts;
    }

    /** The open batch of the person through the production service; a row of the same shape when no AI agent is runnable here. */
    private function batch(UploadBatchQuery $queries, Viewer $viewer, MedicalSubject $subject, int $userId): ?string
    {
        $made = $queries->create($viewer, $subject, $viewer->user, true, app(AgentRegistry::class)->providers(), true);
        if (isset($made['batchId'])) {
            return $made['batchId'];
        }
        if ($made['result'] !== 'unavailable' && $made['result'] !== 'notice_required') {
            return null;
        }
        $id = (string) Str::uuid();
        $now = Carbon::now();
        DB::table('medical_upload_batches')->insert([
            'id' => $id, 'subject_id' => (string) $subject->getKey(), 'created_by' => $userId, 'acted_by' => $userId, 'status' => 'open',
            'expires_at' => $now->copy()->addDays((int) config('medical.upload_batches.expire_days')), 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  array{itemId: string, documentId: string}  $item
     * @param  array<string, mixed>|null  $payload
     */
    private function run(StagingContext $ctx, string $subjectId, string $batchId, array $item, string $status, ?array $payload, int $by): void
    {
        $queued = CarbonImmutable::now()->subMinutes(20);
        $row = MedicalAiExtractionFactory::new()->create([
            'subject_id' => $subjectId, 'document_id' => $item['documentId'], 'status' => $status,
            'failure_code' => $status === 'failed' ? 'unreadable_document' : null,
            'agent' => 'codex', 'model' => $status === 'ready' ? 'default' : null,
            'schema_version' => ExtractionSchema::SCHEMA_VERSION, 'prompt_version' => ExtractionSchema::PROMPT_VERSION,
            'started_by' => $by, 'payload' => $payload,
            'entry_count' => $payload === null ? null : count(ProposalEntries::keys($payload)),
            'pages_read' => $status === 'ready' ? 1 : null, 'pages_total' => 1,
            'available_at' => $status === 'waiting' ? CarbonImmutable::now()->addHours(3) : null,
            'waiting_since' => $status === 'waiting' ? $queued->addSeconds(10) : null,
            'queued_at' => $queued, 'started_at' => $status === 'waiting' ? null : $queued->addSeconds(4),
            'finished_at' => in_array($status, ['ready', 'failed'], true) ? $queued->addSeconds(41) : null,
            'created_at' => $queued, 'updated_at' => $queued->addSeconds(41),
        ]);
        DB::table('medical_ai_extractions')->where('id', $row->getKey())->update(['upload_batch_id' => $batchId]);
        DB::table('medical_upload_batch_items')->where('id', $item['itemId'])->update(['state' => 'sent', 'extraction_id' => $row->getKey(), 'requested_by' => $by]);
        AiCallFactory::new()->create([
            'extraction_id' => $row->getKey(), 'subject_id' => $subjectId, 'document_id' => $item['documentId'], 'started_by' => $by,
            'agent' => 'codex', 'model' => $status === 'ready' ? 'default' : null, 'attempt' => 1,
            'status' => match ($status) {
                'ready' => 'succeeded', 'failed' => 'failed', default => 'queued'
            },
            'failure_code' => $status === 'failed' ? 'unreadable_document' : null,
            'queued_at' => $queued, 'started_at' => $status === 'waiting' ? null : $queued->addSeconds(4),
            'finished_at' => in_array($status, ['ready', 'failed'], true) ? $queued->addSeconds(41) : null,
            'duration_ms' => in_array($status, ['ready', 'failed'], true) ? 37000 : null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $visits
     * @param  list<array<string, mixed>>  $medications
     * @param  list<array<string, mixed>>  $allergies
     * @param  list<array<string, mixed>>  $vaccinations
     * @return array<string, mixed>
     */
    private function payload(StagingContext $ctx, int $dayOffset, array $visits, array $medications, array $allergies, array $vaccinations): array
    {
        return [
            'schema_version' => ExtractionSchema::SCHEMA_VERSION,
            'document_date' => $ctx->day($dayOffset),
            'specialty_key' => 'general_practice',
            'visits' => $visits,
            'medications' => $medications,
            'allergies' => $allergies,
            'vaccinations' => $vaccinations,
        ];
    }

    /** Coloured bars and a line of text drawn with GD; a different picture per position so the files differ. */
    private function image(string $text, int $n): string
    {
        $img = imagecreatetruecolor(640, 420);
        imagefilledrectangle($img, 0, 0, 639, 419, imagecolorallocate($img, 244, 247, 250));
        imagefilledrectangle($img, 0, 0, 639, 60, imagecolorallocate($img, 60 + $n * 20, 110, 160));
        imagestring($img, 5, 20, 22, 'SYNTHETIC DEMO DOCUMENT', imagecolorallocate($img, 255, 255, 255));
        $ink = imagecolorallocate($img, 30, 40, 55);
        imagestring($img, 5, 20, 90, $text, $ink);
        imagestring($img, 3, 20, 130, 'Not a real medical document.', $ink);
        for ($i = 0; $i < 6; $i++) {
            imagefilledrectangle($img, 20, 180 + $i * 32, 220 + (($i + $n) * 61) % 330, 196 + $i * 32, imagecolorallocate($img, 190, 205, 225));
        }
        ob_start();
        imagejpeg($img, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
