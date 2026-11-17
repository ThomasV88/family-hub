<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The run mode (entries: one image-to-entries call as before; full: transcribe then extract; text: transcribe only) and
 * the terminal status text_saved of a text-only run. A text-only run never holds a proposal. pages_total of the stored
 * text is the real page count of the document (a long PDF is read up to the cap and is partial), so its limit widens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE medical_ai_extractions ADD COLUMN mode varchar(8) NOT NULL DEFAULT 'entries'");
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_mode_check CHECK (mode IN ('entries', 'full', 'text'))");

        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_status_check');
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_status_check CHECK (status IN ('queued', 'running', 'waiting', 'ready', 'failed', 'cancelled', 'superseded', 'text_saved'))");
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_text_saved_check CHECK (status <> 'text_saved' OR mode = 'text')");
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_text_mode_check CHECK (mode <> 'text' OR status NOT IN ('ready', 'superseded'))");

        DB::statement('ALTER TABLE medical_document_texts DROP CONSTRAINT medical_document_texts_pages_total_check');
        DB::statement('ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_pages_total_check CHECK (pages_total BETWEEN 1 AND 10000)');
    }

    public function down(): void
    {
        // All guards run before any change, so a refusal leaves the schema exactly as it was.
        $modes = (int) DB::table('medical_ai_extractions')->where(fn ($q) => $q->where('mode', '<>', 'entries')->orWhere('status', 'text_saved'))->count();
        if ($modes > 0) {
            throw new RuntimeException("Refusing to roll back: {$modes} extraction row(s) have a mode other than 'entries' or status 'text_saved'. Resolve them first (they are never rewritten silently).");
        }
        $long = (int) DB::table('medical_document_texts')->where('pages_total', '>', 50)->count();
        if ($long > 0) {
            throw new RuntimeException("Refusing to roll back: {$long} stored text row(s) have more than 50 pages. Resolve them first (they are never rewritten silently).");
        }

        DB::statement('ALTER TABLE medical_document_texts DROP CONSTRAINT medical_document_texts_pages_total_check');
        DB::statement('ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_pages_total_check CHECK (pages_total BETWEEN 1 AND 50)');

        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_text_mode_check');
        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_text_saved_check');
        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_status_check');
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_status_check CHECK (status IN ('queued', 'running', 'waiting', 'ready', 'failed', 'cancelled', 'superseded'))");
        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_mode_check');
        DB::statement('ALTER TABLE medical_ai_extractions DROP COLUMN mode');
    }
};
