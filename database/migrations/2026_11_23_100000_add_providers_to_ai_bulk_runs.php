<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider set the admin acknowledged at Start, frozen on the run (comma separated keys, the format of
 * ai_notice_acks.providers). The tick and the job use it instead of the admin's live acknowledgment, so a later
 * acknowledgment or a newly enabled provider never widens an old run. Existing rows get the Phase 8 default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bulk_runs', function (Blueprint $table) {
            $table->string('providers', 40)->default('codex');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bulk_runs', function (Blueprint $table) {
            $table->dropColumn('providers');
        });
    }
};
