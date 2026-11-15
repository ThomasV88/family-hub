<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_allergies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            $table->string('substance', 120);
            $table->string('reaction', 200)->nullable();
            $table->string('severity', 10);
            $table->string('source', 6)->default('manual');
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index('subject_id', 'medical_allergies_subject_index');
        });

        DB::statement("ALTER TABLE medical_allergies ADD CONSTRAINT medical_allergies_severity_check CHECK (severity IN ('mild', 'moderate', 'severe', 'unknown'))");
        DB::statement("ALTER TABLE medical_allergies ADD CONSTRAINT medical_allergies_source_check CHECK (source IN ('manual', 'ai'))");
        DB::statement("ALTER TABLE medical_allergies ADD CONSTRAINT medical_allergies_verified_check CHECK ((source = 'ai') OR (verified_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_allergies');
    }
};
