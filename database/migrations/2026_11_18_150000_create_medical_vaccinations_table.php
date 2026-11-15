<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_vaccinations', function (Blueprint $table) {
            // Internal bigint identity: notification_outbox.subject_id and audit_logs.subject_id are bigint. The public
            // uuid is the only id that appears in URLs, props and responses.
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            $table->string('vaccine', 120);
            $table->date('given_on');
            $table->date('next_due_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('source', 6)->default('manual');
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index('subject_id', 'medical_vaccinations_subject_index');
        });

        DB::statement("ALTER TABLE medical_vaccinations ADD CONSTRAINT medical_vaccinations_source_check CHECK (source IN ('manual', 'ai'))");
        DB::statement("ALTER TABLE medical_vaccinations ADD CONSTRAINT medical_vaccinations_verified_check CHECK ((source = 'ai') OR (verified_at IS NULL))");
        DB::statement('ALTER TABLE medical_vaccinations ADD CONSTRAINT medical_vaccinations_due_check CHECK (next_due_on IS NULL OR next_due_on >= given_on)');
        DB::statement('CREATE INDEX medical_vaccinations_next_due_index ON medical_vaccinations (next_due_on) WHERE next_due_on IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_vaccinations');
    }
};
