<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resumable server-side upload batches (Phase 15, GitHub #14): one batch per person, its items (one per uploaded
 * document), and the pending marker medical_documents.upload_batch_id. Deleting a batch deletes its unfinished documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_upload_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('open');
            // Plain uuid (no foreign key) to avoid a cycle with the items table.
            $table->uuid('current_item_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index('expires_at', 'medical_upload_batches_expires_index');
        });
        DB::statement("ALTER TABLE medical_upload_batches ADD CONSTRAINT medical_upload_batches_status_check CHECK (status IN ('open', 'finished'))");
        DB::statement("CREATE UNIQUE INDEX medical_upload_batches_one_open ON medical_upload_batches (subject_id) WHERE status = 'open'");

        Schema::create('medical_upload_batch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('medical_upload_batches')->cascadeOnDelete();
            $table->uuid('document_id')->unique();
            $table->smallInteger('position');
            $table->string('state', 8)->default('pending');
            $table->uuid('extraction_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('rescan_count')->default(0);
            $table->timestampTz('rescan_requested_at')->nullable();
            $table->jsonb('adjustments')->default('{"brightness":100,"contrast":100,"bw":false}');
            $table->jsonb('choices')->default('{}');
            $table->uuid('duplicate_of')->nullable();
            $table->timestampsTz();

            $table->unique(['batch_id', 'position'], 'medical_upload_batch_items_position_unique');
            $table->index(['batch_id', 'state'], 'medical_upload_batch_items_state_index');
            $table->foreign('document_id')->references('id')->on('medical_documents')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE medical_upload_batch_items ADD CONSTRAINT medical_upload_batch_items_state_check CHECK (state IN ('pending', 'sent', 'done'))");
        DB::statement('ALTER TABLE medical_upload_batch_items ADD CONSTRAINT medical_upload_batch_items_rescan_check CHECK (rescan_count BETWEEN 0 AND 5)');

        DB::statement('ALTER TABLE medical_documents ADD COLUMN upload_batch_id uuid NULL');
        DB::statement('ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_upload_batch_foreign FOREIGN KEY (upload_batch_id) REFERENCES medical_upload_batches (id) ON DELETE CASCADE');
        DB::statement('CREATE INDEX medical_documents_upload_batch_index ON medical_documents (upload_batch_id) WHERE upload_batch_id IS NOT NULL');

        DB::statement('ALTER TABLE medical_ai_extractions ADD COLUMN upload_batch_id uuid NULL, ADD COLUMN fresh_read boolean NOT NULL DEFAULT false, ADD COLUMN adjustments jsonb NULL');
        DB::statement('CREATE INDEX medical_ai_extractions_upload_batch_index ON medical_ai_extractions (upload_batch_id) WHERE upload_batch_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS medical_ai_extractions_upload_batch_index');
        DB::statement('ALTER TABLE medical_ai_extractions DROP COLUMN adjustments, DROP COLUMN fresh_read, DROP COLUMN upload_batch_id');

        DB::statement('DROP INDEX IF EXISTS medical_documents_upload_batch_index');
        DB::statement('ALTER TABLE medical_documents DROP CONSTRAINT medical_documents_upload_batch_foreign');
        DB::statement('ALTER TABLE medical_documents DROP COLUMN upload_batch_id');

        Schema::dropIfExists('medical_upload_batch_items');
        Schema::dropIfExists('medical_upload_batches');
    }
};
