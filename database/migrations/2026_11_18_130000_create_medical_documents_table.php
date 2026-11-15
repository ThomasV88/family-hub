<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            // Constrained by the composite foreign key below (the visit must belong to the same subject).
            $table->uuid('visit_id')->nullable();
            $table->string('kind', 5);
            $table->string('title', 120);
            $table->string('mime', 40);
            $table->integer('size_bytes');
            $table->char('sha256', 64);
            $table->string('path', 200);
            $table->string('thumb_path', 200)->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('source', 6)->default('manual');
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('visit_id', 'medical_documents_visit_index');
        });

        DB::statement("ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_kind_check CHECK (kind IN ('pdf', 'image', 'heic'))");
        DB::statement('ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_size_check CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_thumb_check CHECK ((kind = 'image') = (thumb_path IS NOT NULL))");
        DB::statement("ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_source_check CHECK (source IN ('manual', 'ai'))");
        DB::statement('ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_verified_check CHECK ((source = \'ai\') OR (verified_at IS NULL))');
        // Postgres 15+ column list: deleting a visit clears only visit_id, so the document stays with its person.
        DB::statement('ALTER TABLE medical_documents ADD CONSTRAINT medical_documents_visit_subject_foreign FOREIGN KEY (visit_id, subject_id) REFERENCES medical_visits (id, subject_id) ON DELETE SET NULL (visit_id)');
        DB::statement('CREATE INDEX medical_documents_subject_created_desc_index ON medical_documents (subject_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_documents');
    }
};
