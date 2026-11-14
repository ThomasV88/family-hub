<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_medications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('dose', 120)->nullable();
            $table->string('schedule', 160)->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('source', 6)->default('manual');
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index('subject_id', 'medical_medications_subject_index');
        });

        DB::statement("ALTER TABLE medical_medications ADD CONSTRAINT medical_medications_source_check CHECK (source IN ('manual', 'ai'))");
        DB::statement("ALTER TABLE medical_medications ADD CONSTRAINT medical_medications_verified_check CHECK ((source = 'ai') OR (verified_at IS NULL))");
        DB::statement('ALTER TABLE medical_medications ADD CONSTRAINT medical_medications_dates_check CHECK (ended_on IS NULL OR started_on IS NULL OR ended_on >= started_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_medications');
    }
};
