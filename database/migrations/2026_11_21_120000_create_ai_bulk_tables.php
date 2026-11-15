<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk re-generation bookkeeping: ids, enums, counts and times only. Plain id columns (no foreign keys), so history and
 * counts survive document, person or user deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_bulk_dry_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('requested_by');
            $table->string('scope', 12);
            $table->smallInteger('daily_cap');
            $table->integer('eligible');
            $table->integer('skipped_consent');
            $table->integer('skipped_unsupported');
            $table->integer('skipped_decided');
            $table->integer('skipped_active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('consumed_at')->nullable();
        });
        DB::statement("ALTER TABLE ai_bulk_dry_runs ADD CONSTRAINT ai_bulk_dry_runs_scope_check CHECK (scope IN ('no_proposal', 'last_failed', 'all'))");

        Schema::create('ai_bulk_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('started_by');
            $table->string('scope', 12);
            $table->smallInteger('daily_cap');
            $table->string('status', 10);
            $table->uuid('dry_run_id');
            $table->integer('eligible_total');
            $table->integer('skipped_count')->default(0);
            $table->timestampTz('last_enqueued_at')->nullable();
            $table->timestampTz('blocked_since')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement("ALTER TABLE ai_bulk_runs ADD CONSTRAINT ai_bulk_runs_scope_check CHECK (scope IN ('no_proposal', 'last_failed', 'all'))");
        DB::statement("ALTER TABLE ai_bulk_runs ADD CONSTRAINT ai_bulk_runs_status_check CHECK (status IN ('active', 'paused', 'cancelled', 'completed'))");
        DB::statement('ALTER TABLE ai_bulk_runs ADD CONSTRAINT ai_bulk_runs_daily_cap_check CHECK (daily_cap BETWEEN 1 AND 100)');
        DB::statement("CREATE UNIQUE INDEX ai_bulk_runs_one_open ON ai_bulk_runs ((true)) WHERE status IN ('active', 'paused')");

        Schema::create('ai_bulk_run_items', function (Blueprint $table) {
            $table->uuid('bulk_run_id');
            $table->uuid('document_id');
            $table->uuid('extraction_id')->nullable();
            $table->timestampTz('enqueued_at')->useCurrent();
            $table->string('outcome', 10)->nullable();

            $table->primary(['bulk_run_id', 'document_id']);
            $table->index('enqueued_at', 'ai_bulk_run_items_enqueued_index');
        });
        DB::statement("ALTER TABLE ai_bulk_run_items ADD CONSTRAINT ai_bulk_run_items_outcome_check CHECK (outcome IS NULL OR outcome IN ('read', 'failed', 'cancelled', 'skipped', 'gone'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_bulk_run_items');
        Schema::dropIfExists('ai_bulk_runs');
        Schema::dropIfExists('ai_bulk_dry_runs');
    }
};
