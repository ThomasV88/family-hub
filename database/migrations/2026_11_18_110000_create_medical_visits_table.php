<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            $table->date('visited_on');
            $table->string('doctor', 120)->nullable();
            // No CHECK listing the keys: a later taxonomy version adds keys without a migration.
            $table->string('specialty_key', 40);
            $table->string('specialty_other', 80)->nullable();
            $table->string('diagnosis', 2000)->nullable();
            $table->string('notes', 4000)->nullable();
            $table->string('source', 6)->default('manual');
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            // Target of the composite foreign key that keeps a document and its visit on one person.
            $table->unique(['id', 'subject_id'], 'medical_visits_id_subject_unique');
            $table->index(['subject_id', 'visited_on', 'id'], 'medical_visits_subject_date_index');
        });

        DB::statement("ALTER TABLE medical_visits ADD CONSTRAINT medical_visits_source_check CHECK (source IN ('manual', 'ai'))");
        DB::statement("ALTER TABLE medical_visits ADD CONSTRAINT medical_visits_other_check CHECK ((specialty_key = 'other') = (specialty_other IS NOT NULL))");
        DB::statement("ALTER TABLE medical_visits ADD CONSTRAINT medical_visits_verified_check CHECK ((source = 'ai') OR (verified_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_visits');
    }
};
