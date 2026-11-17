<?php

namespace Tests\Support;

use App\Medical\Documents\Text\SaveOutcome;
use App\Medical\Queries\DocumentTextStore;
use App\Models\MedicalDocument;
use Illuminate\Support\Facades\DB;

/** Stores recognised text for a test document through the real store (the only writer). */
final class DocumentTextFixtures
{
    public const CANARY = 'ZxCanaryOcr';

    public const SLOVAK = 'Žltačka a ľadviny, Penicilín 500 mg';

    public const POLISH = 'zażółć gęślą jaźń, Łukasz';

    public const ENGLISH = 'Take paracetamol after meals';

    /** One page per language, each carrying the canary. */
    public static function samplePages(): array
    {
        return [
            self::CANARY.' '.self::SLOVAK,
            self::POLISH.' '.self::CANARY,
            self::ENGLISH.' '.self::CANARY,
        ];
    }

    /**
     * Turns AI consent on for the person when needed (the store refuses otherwise) and saves $pages.
     *
     * @param  list<string>  $pages
     */
    public static function save(MedicalDocument $doc, array $pages, ?int $pagesTotal = null, bool $complete = true, ?string $language = 'sk', int $startedBy = 1): SaveOutcome
    {
        DB::table('medical_subjects')->where('id', $doc->subject_id)->update(['ai_consent' => true]);

        return app(DocumentTextStore::class)->saveStandalone((string) $doc->id, $startedBy, 'codex', 'test', $language, $pages, $pagesTotal ?? count($pages), $complete);
    }

    public static function row(MedicalDocument|string $doc): ?object
    {
        return DB::table('medical_document_texts')->where('document_id', $doc instanceof MedicalDocument ? $doc->id : $doc)->first();
    }
}
