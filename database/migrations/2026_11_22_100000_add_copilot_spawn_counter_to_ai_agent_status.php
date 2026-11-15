<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-local-day count of CLI spawns per agent (the repair call is a second spawn of one attempt). The Copilot daily
 * ceiling counts these, not call-log rows. Integers and a date only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agent_status', function (Blueprint $table) {
            $table->date('spawn_day')->nullable();
            $table->unsignedInteger('spawn_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ai_agent_status', function (Blueprint $table) {
            $table->dropColumn(['spawn_day', 'spawn_count']);
        });
    }
};
