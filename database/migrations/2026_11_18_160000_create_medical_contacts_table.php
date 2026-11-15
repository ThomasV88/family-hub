<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_id')->constrained('medical_subjects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('relation', 60)->nullable();
            $table->string('phone', 40);
            $table->smallInteger('sort');
            $table->timestampsTz();

            $table->unique(['subject_id', 'sort'], 'medical_contacts_subject_sort_unique');
        });

        DB::statement('ALTER TABLE medical_contacts ADD CONSTRAINT medical_contacts_sort_check CHECK (sort >= 0 AND sort < 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_contacts');
    }
};
