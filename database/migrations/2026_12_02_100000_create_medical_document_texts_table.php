<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // unaccent is a trusted extension (PostgreSQL 13+): the database owner role may create it without superuser.
        // Idempotent; a production or staging database that cannot create it must stop here with a clear reason.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        } catch (Throwable $e) {
            if (app()->environment(['production', 'staging'])) {
                throw new RuntimeException('The owner role must be able to create the trusted "unaccent" extension (or a superuser must run CREATE EXTENSION unaccent once): refusing to create the recognised text table without it.', 0, $e);
            }
            throw $e;
        }

        // unaccent() itself is only STABLE, so an index or generated column cannot use it. This wrapper is IMMUTABLE and
        // schema-qualified, so pg_restore (empty search_path) can rebuild the generated column. CREATE OR REPLACE:
        // migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.fh_unaccent(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
SQL);

        Schema::create('medical_document_texts', function (Blueprint $table) {
            $table->uuid('document_id')->primary();
            $table->uuid('subject_id');
            // Plain ids (no foreign keys), like ai_calls: provenance for staging:audit, never a join target.
            $table->uuid('extraction_id')->nullable();
            $table->unsignedBigInteger('started_by');
            $table->string('agent', 20)->nullable();
            $table->string('model', 60)->nullable();
            $table->smallInteger('text_version');
            $table->string('language', 5)->nullable();
            $table->smallInteger('pages_read');
            $table->smallInteger('pages_total');
            $table->boolean('complete');
            $table->text('body');
            $table->timestampsTz();

            $table->index('subject_id', 'medical_document_texts_subject_index');
        });

        DB::statement("ALTER TABLE medical_document_texts ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('simple', public.fh_unaccent(body))) STORED");
        DB::statement('CREATE INDEX medical_document_texts_search_index ON medical_document_texts USING GIN (search)');
        DB::statement('ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_document_subject_foreign FOREIGN KEY (document_id, subject_id) REFERENCES medical_documents (id, subject_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_body_check CHECK (char_length(body) <= 200000)');
        DB::statement('ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_version_check CHECK (text_version >= 1)');
        DB::statement('ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_pages_total_check CHECK (pages_total BETWEEN 1 AND 50)');
        DB::statement('ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_pages_read_check CHECK (pages_read BETWEEN 0 AND pages_total)');
        DB::statement('ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_complete_check CHECK (NOT complete OR pages_read = pages_total)');
        DB::statement("ALTER TABLE medical_document_texts ADD CONSTRAINT medical_document_texts_language_check CHECK (language IS NULL OR language IN ('sk', 'pl', 'en', 'mixed', 'other'))");
    }

    public function down(): void
    {
        if (Schema::hasTable('medical_document_texts') && DB::table('medical_document_texts')->exists()) {
            throw new RuntimeException('Refusing to drop medical_document_texts: it still holds recognised text. Remove the rows first (consent withdrawal or the per-document removal).');
        }

        Schema::dropIfExists('medical_document_texts');
        DB::statement('DROP FUNCTION IF EXISTS public.fh_unaccent(text)');
        // The unaccent extension stays: other objects may use it.
    }
};
