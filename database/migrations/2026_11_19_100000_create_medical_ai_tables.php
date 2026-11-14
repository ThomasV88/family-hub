<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target of the composite foreign key below: a proposal's document must belong to the proposal's person.
        DB::statement('ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_id_subject_unique UNIQUE (id, subject_id)');

        Schema::create('medical_ai_extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            $table->uuid('document_id');
            $table->string('status', 12);
            $table->string('cancel_reason', 8)->nullable();
            $table->string('failure_code', 24)->nullable();
            $table->string('agent', 20)->default('codex');
            $table->string('model', 60)->nullable();
            $table->smallInteger('schema_version');
            $table->smallInteger('prompt_version');
            // Plain column: history of who started a run must not depend on the user row.
            $table->unsignedBigInteger('started_by');
            $table->jsonb('payload')->nullable();
            $table->smallInteger('entry_count')->nullable();
            $table->smallInteger('pages_read')->nullable();
            $table->smallInteger('pages_total')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('queued_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_status_check CHECK (status IN ('queued', 'running', 'ready', 'failed', 'cancelled', 'superseded'))");
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_cancel_reason_check CHECK (cancel_reason IS NULL OR cancel_reason IN ('user', 'consent', 'access'))");
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_payload_check CHECK ((payload IS NOT NULL) = (status IN ('ready', 'superseded')))");
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_cancel_check CHECK ((cancel_reason IS NOT NULL) = (status = 'cancelled'))");
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_failure_check CHECK ((failure_code IS NOT NULL) = (status = 'failed'))");
        DB::statement('ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_document_subject_foreign FOREIGN KEY (document_id, subject_id) REFERENCES medical_documents (id, subject_id) ON DELETE CASCADE');
        DB::statement("CREATE UNIQUE INDEX medical_ai_extractions_one_active ON medical_ai_extractions (document_id) WHERE status IN ('queued', 'running')");
        DB::statement("CREATE UNIQUE INDEX medical_ai_extractions_one_ready ON medical_ai_extractions (document_id) WHERE status = 'ready'");
        DB::statement('CREATE INDEX medical_ai_extractions_subject_document_index ON medical_ai_extractions (subject_id, document_id, queued_at DESC)');
        DB::statement("CREATE INDEX medical_ai_extractions_open_index ON medical_ai_extractions (status, queued_at) WHERE status IN ('queued', 'running')");

        Schema::create('medical_ai_decisions', function (Blueprint $table) {
            $table->foreignUuid('extraction_id')->constrained('medical_ai_extractions')->cascadeOnDelete();
            $table->string('entry_key', 20);
            $table->string('decision', 8);
            $table->string('record_type', 12)->nullable();
            $table->string('record_id', 40)->nullable();
            $table->boolean('edited')->default(false);
            $table->unsignedBigInteger('decided_by');
            $table->timestampTz('decided_at')->useCurrent();

            $table->primary(['extraction_id', 'entry_key']);
        });

        DB::statement("ALTER TABLE medical_ai_decisions ADD CONSTRAINT medical_ai_decisions_decision_check CHECK (decision IN ('accepted', 'rejected'))");
        DB::statement("ALTER TABLE medical_ai_decisions ADD CONSTRAINT medical_ai_decisions_record_type_check CHECK (record_type IS NULL OR record_type IN ('visit', 'medication', 'allergy', 'vaccination'))");
        DB::statement("ALTER TABLE medical_ai_decisions ADD CONSTRAINT medical_ai_decisions_record_check CHECK ((decision = 'accepted') = (record_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_ai_decisions');
        Schema::dropIfExists('medical_ai_extractions');
        DB::statement('ALTER TABLE medical_documents DROP CONSTRAINT IF EXISTS medical_documents_id_subject_unique');
    }
};
