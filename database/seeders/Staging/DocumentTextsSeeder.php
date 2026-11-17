<?php

namespace Database\Seeders\Staging;

use App\Medical\Ai\ExtractionSchema;
use App\Medical\Documents\Text\SaveOutcome;
use App\Medical\Queries\DocumentTextStore;
use Database\Factories\MedicalAiExtractionFactory;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic recognised texts (Phase 13) written through the production DocumentTextStore, so the guards (consent on, not
 * archived, normalisation, page counting) are the real ones. The texts are invented demo prose with diacritics and made-up
 * names; they are NOT the OCR of the seeded pictures (the seeded PDFs and images say "SYNTHETIC DEMO DOCUMENT" and a title),
 * and no provider is ever called. docs/ops/staging-data.md has the scenario map.
 *
 * Scenarios (document key: what it shows):
 *  - blood_pdf (adult linked to parent1): three Slovak pages, complete; has a seeded entries proposal.
 *  - discharge_pdf (grandparent): two Polish pages, complete, belonging to the seeded proposal, which becomes a two-step
 *    (mode full, prompt version 2) run.
 *  - partial_pdf (grandparent): English text, first four of nine pages read (partial).
 *  - text_only_pdf (adult linked): three Slovak pages with an empty (illegible) second page, written by a text-only run
 *    (mode text, status text_saved) whose id the text row carries.
 *  - prescription_jpg (hidden person Marek Skrytý): a text, searchable by nobody who cannot see the person. The person gets
 *    AI consent here because the store refuses a write without it.
 *  - archived_pdf (adult linked, archived document): written while the document was still active, then archived again,
 *    so it exists but is hidden from the search and the text view.
 *
 * Reseed: the people go (cascade removes the documents and their texts); StagingSeeder also deletes the text rows by
 * started_by, so a text of a kept person cannot survive. No AI call rows are written for these runs (ai_calls is append-only).
 */
final class DocumentTextsSeeder
{
    /**
     * @param  array<string, array{id: string, name: string}>  $subjects
     * @param  array<string, array{id: string, subject_id: string, kind: string}>  $documents
     * @return array{texts: int, extractions: int}
     */
    public function seed(StagingContext $ctx, array $subjects, array $documents): array
    {
        $by = $ctx->user('parent1')->id;
        $store = app(DocumentTextStore::class);
        $counts = ['texts' => 0, 'extractions' => 0];

        $save = function (string $key, array $pages, string $language, ?string $extractionId = null, ?int $total = null, bool $complete = true) use (&$counts, $documents, $store, $by): void {
            if (! isset($documents[$key])) {
                return;
            }
            $outcome = $store->saveStandalone($documents[$key]['id'], $by, 'codex', 'default', $language, $pages, $total ?? count($pages), $complete, $extractionId);
            if ($outcome === SaveOutcome::Saved) {
                $counts['texts']++;
            }
        };

        $save('blood_pdf', [
            "Laboratórne výsledky (ukážka)\nPacient: Anna Vzorová (demo)\nŽltačka vylúčená, pečeňové testy v norme.",
            "Obličky a ľadviny: kreatinín v norme.\nAlergia na Penicilín potvrdená, náhrada: makrolidy.",
            'Odporúčanie: kontrola o šesť mesiacov. Text je vymyslený pre ukážku (demo).',
        ], 'sk');

        $two = $documents['discharge_pdf']['id'] ?? null;
        $twoStep = $two === null ? null : DB::table('medical_ai_extractions')->where('document_id', $two)->where('status', 'ready')->value('id');
        if ($twoStep !== null) {
            DB::table('medical_ai_extractions')->where('id', $twoStep)->update(['mode' => 'full', 'prompt_version' => ExtractionSchema::TEXT_PROMPT_VERSION]);
            $twoStep = (string) $twoStep;
        }
        $save('discharge_pdf', [
            "Karta wypisowa (przykład)\nPacjent: Jan Nowak (demo)\nRozpoznanie: kontrola roczna, stan stabilny.",
            "Zalecenia: zażółć gęślą jaźń to tylko test znaków. Lekarz prowadzący: Łukasz Wiśniewski (demo).\nSzczepienie przeciw pneumokokom uzupełnić.",
        ], 'pl', $twoStep);

        $save('partial_pdf', [
            "Outpatient report (demo), page 1\nTake paracetamol 500 mg after meals if the pain returns.",
            'Page 2: the follow-up visit was moved to the next month. Nothing here is real.',
            'Page 3: blood pressure was within the usual range for the age group.',
            'Page 4: the patient was asked to keep a short diary of symptoms.',
        ], 'en', null, 9, false);

        // Text-only run: the run row first (its id goes into the text row), then the text through the store.
        if (isset($documents['text_only_pdf'])) {
            $doc = $documents['text_only_pdf'];
            $queued = $ctx->at(-1, 10, 0);
            $row = MedicalAiExtractionFactory::new()->create([
                'subject_id' => $doc['subject_id'], 'document_id' => $doc['id'], 'status' => 'text_saved', 'mode' => 'text',
                'agent' => 'codex', 'model' => 'default', 'schema_version' => ExtractionSchema::SCHEMA_VERSION,
                'prompt_version' => ExtractionSchema::TEXT_PROMPT_VERSION, 'started_by' => $by, 'payload' => null, 'entry_count' => null,
                'pages_read' => 3, 'pages_total' => 3, 'queued_at' => $queued, 'started_at' => $queued->addSeconds(3), 'finished_at' => $queued->addSeconds(52),
                'created_at' => $queued, 'updated_at' => $queued->addSeconds(52),
            ]);
            $counts['extractions']++;
            $save('text_only_pdf', [
                "Správa z ambulancie (ukážka)\nDiagnóza: kontrola po prechladnutí, bez komplikácií.",
                '',
                'Posledná strana: odporúčaný pokoj a pitný režim. Text je vymyslený (demo).',
            ], 'sk', (string) $row->getKey());
        }

        // The hidden person: consent on (the store refuses a write without it), words nobody else's search may find.
        if (isset($documents['prescription_jpg'], $subjects['hidden'])) {
            DB::table('medical_subjects')->where('id', $subjects['hidden']['id'])->update([
                'ai_consent' => true, 'ai_consent_changed_at' => $ctx->at(-20, 9), 'ai_consent_changed_by' => $by,
            ]);
            $save('prescription_jpg', ['Skrytý recept (demo): Warfarín 5 mg, dávkovanie podľa lekára.'], 'sk');
        }

        // The archived document: text written while the document was active, then archived as before.
        if (isset($documents['archived_pdf'])) {
            $id = $documents['archived_pdf']['id'];
            $archived = DB::table('medical_documents')->where('id', $id)->first(['archived_at', 'archived_by']);
            DB::table('medical_documents')->where('id', $id)->update(['archived_at' => null, 'archived_by' => null]);
            $save('archived_pdf', ['Archivovaný nález (demo): Betaloc 50 mg, kontrola tlaku.', 'Druhá strana: vymyslený text.'], 'sk');
            DB::table('medical_documents')->where('id', $id)->update(['archived_at' => $archived->archived_at, 'archived_by' => $archived->archived_by]);
        }

        return $counts;
    }
}
