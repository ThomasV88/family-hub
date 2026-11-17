<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One AI recipe import from a web page. The reduced page text lives here only while the import is active (fetching,
 * queued, running) and is cleared at every terminal state; the payload exists only for a ready draft. user_id is a plain
 * column (no foreign key), like ai_calls.started_by, so the history does not depend on the user row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('host', 255);
            $table->string('status', 10);
            $table->string('cancel_reason', 8)->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->string('failure_code', 24)->nullable();
            $table->string('fetch_reason', 24)->nullable();
            $table->string('agent', 20)->nullable();
            $table->string('model', 60)->nullable();
            $table->smallInteger('schema_version')->default(1);
            $table->smallInteger('prompt_version')->default(1);
            $table->string('source_kind', 10)->nullable();
            $table->text('source_text')->nullable();
            $table->boolean('text_cut')->default(false);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE recipe_imports ADD CONSTRAINT recipe_imports_status_check CHECK (status IN ('fetching', 'queued', 'running', 'ready', 'failed', 'cancelled', 'used'))");
        DB::statement("ALTER TABLE recipe_imports ADD CONSTRAINT recipe_imports_cancel_reason_check CHECK (cancel_reason IS NULL OR cancel_reason IN ('user', 'access'))");
        DB::statement("ALTER TABLE recipe_imports ADD CONSTRAINT recipe_imports_fetch_reason_check CHECK (fetch_reason IS NULL OR fetch_reason IN ('bad_url', 'blocked_address', 'too_many_redirects', 'http_status', 'too_large', 'timeout', 'network', 'not_html', 'empty'))");
        DB::statement("ALTER TABLE recipe_imports ADD CONSTRAINT recipe_imports_source_kind_check CHECK (source_kind IS NULL OR source_kind IN ('json_ld', 'microdata', 'text'))");
        DB::statement("ALTER TABLE recipe_imports ADD CONSTRAINT recipe_imports_failure_check CHECK ((failure_code IS NOT NULL) = (status = 'failed'))");
        DB::statement("ALTER TABLE recipe_imports ADD CONSTRAINT recipe_imports_payload_check CHECK (payload IS NULL OR status = 'ready')");
        DB::statement("ALTER TABLE recipe_imports ADD CONSTRAINT recipe_imports_source_text_check CHECK (source_text IS NULL OR status IN ('fetching', 'queued', 'running'))");
        DB::statement("CREATE UNIQUE INDEX recipe_imports_one_active_per_url ON recipe_imports (user_id, url_hash) WHERE status IN ('fetching', 'queued', 'running')");
        DB::statement("CREATE INDEX recipe_imports_open_index ON recipe_imports (status, created_at) WHERE status IN ('fetching', 'queued', 'running')");
        DB::statement('CREATE INDEX recipe_imports_user_index ON recipe_imports (user_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_imports');
    }
};
