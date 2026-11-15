<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->date('birth_date')->nullable();
            $table->string('sex', 12)->default('unspecified');
            $table->string('blood_type', 3)->nullable();
            $table->string('notes', 2000)->nullable();
            // The linked app user, when the person has an account. At most one profile per user.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->boolean('self_access_enabled')->default(true);
            $table->boolean('ai_consent')->default(false);
            $table->timestampTz('ai_consent_changed_at')->nullable();
            $table->unsignedBigInteger('ai_consent_changed_by')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE medical_subjects ADD CONSTRAINT medical_subjects_sex_check CHECK (sex IN ('female','male','unspecified'))");
        DB::statement("ALTER TABLE medical_subjects ADD CONSTRAINT medical_subjects_blood_type_check CHECK (blood_type IS NULL OR blood_type IN ('A+','A-','B+','B-','AB+','AB-','O+','O-'))");

        Schema::create('medical_subject_members', function (Blueprint $table) {
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->smallInteger('level');
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->primary(['subject_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE medical_subject_members ADD CONSTRAINT medical_subject_members_level_check CHECK (level IN (1, 2))');
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_subject_members');
        Schema::dropIfExists('medical_subjects');
    }
};
