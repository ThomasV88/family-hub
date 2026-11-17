<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notice for the two-step reading says the recognised text is stored in this app. Members who acknowledged only the
 * older notice acknowledge the new statement once more: its own column, so neither the medical provider set nor the
 * recipe acknowledgment can satisfy it. NULL means "not acknowledged".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_notice_acks', function (Blueprint $table) {
            $table->timestampTz('text_notice_acked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_notice_acks', function (Blueprint $table) {
            $table->dropColumn('text_notice_acked_at');
        });
    }
};
