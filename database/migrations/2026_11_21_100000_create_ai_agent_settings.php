<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-owned agent configuration (enable, order, model), cooldown columns on the agent status and the provider set an
 * AI notice acknowledgment was given for. Existing acknowledgments default to 'codex', the first notice version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_settings', function (Blueprint $table) {
            $table->string('agent', 20)->primary();
            $table->boolean('enabled')->default(false);
            $table->smallInteger('position');
            $table->string('model', 60)->nullable();
            // Plain id, no foreign key: history outlives users.
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE ai_agent_settings ADD CONSTRAINT ai_agent_settings_agent_check CHECK (agent IN ('codex', 'copilot'))");
        DB::statement('ALTER TABLE ai_agent_settings ADD CONSTRAINT ai_agent_settings_position_check CHECK (position BETWEEN 1 AND 9)');
        DB::statement("ALTER TABLE ai_agent_settings ADD CONSTRAINT ai_agent_settings_model_check CHECK (model IS NULL OR model ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,59}\$')");
        DB::statement('ALTER TABLE ai_agent_settings ADD CONSTRAINT ai_agent_settings_position_unique UNIQUE (position) DEFERRABLE INITIALLY DEFERRED');

        DB::table('ai_agent_settings')->insert([
            ['agent' => 'codex', 'enabled' => true, 'position' => 1, 'model' => null],
            ['agent' => 'copilot', 'enabled' => false, 'position' => 2, 'model' => null],
        ]);

        Schema::table('ai_agent_status', function (Blueprint $table) {
            $table->timestampTz('cooldown_until')->nullable();
            $table->smallInteger('quota_hits')->default(0);
            $table->timestampTz('last_quota_at')->nullable();
        });

        Schema::table('ai_notice_acks', function (Blueprint $table) {
            $table->string('providers', 40)->default('codex');
        });
    }

    public function down(): void
    {
        Schema::table('ai_notice_acks', function (Blueprint $table) {
            $table->dropColumn('providers');
        });
        Schema::table('ai_agent_status', function (Blueprint $table) {
            $table->dropColumn(['cooldown_until', 'quota_hits', 'last_quota_at']);
        });
        Schema::dropIfExists('ai_agent_settings');
    }
};
