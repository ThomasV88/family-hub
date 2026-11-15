<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Runtime role that must never hold DELETE/TRUNCATE on ai_calls. */
    public string $appRole = 'fh_app';

    public function up(): void
    {
        $role = DB::selectOne('select exists (select from pg_roles where rolname = ?) as e', [$this->appRole])->e;
        if (! $role && app()->environment(['production', 'staging'])) {
            throw new RuntimeException("Role {$this->appRole} does not exist: refusing to create ai_calls without revoking DELETE/TRUNCATE from it. Create the roles first (docker/postgres/init/01-roles.sh).");
        }

        // Content-free on purpose: ids, enums and times only. Plain id columns (no foreign keys), so the history
        // outlives documents, people and users.
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('extraction_id');
            $table->uuid('subject_id');
            $table->uuid('document_id');
            $table->unsignedBigInteger('started_by');
            $table->string('agent', 20);
            $table->string('model', 60)->nullable();
            $table->smallInteger('attempt')->default(1);
            $table->string('status', 10);
            $table->string('failure_code', 24)->nullable();
            $table->timestampTz('queued_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();

            $table->index([DB::raw('queued_at DESC')], 'ai_calls_queued_at_index');
        });

        DB::statement("ALTER TABLE ai_calls ADD CONSTRAINT ai_calls_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled'))");
        DB::statement('CREATE INDEX ai_calls_subject_queued_index ON ai_calls (subject_id, queued_at DESC)');
        DB::statement('CREATE INDEX ai_calls_started_by_queued_index ON ai_calls (started_by, queued_at DESC)');
        DB::statement('CREATE INDEX ai_calls_status_queued_index ON ai_calls (status, queued_at DESC)');

        // Lifecycle guard: identity immutable, status only forward, terminal rows frozen, the filled-in-later columns
        // may only change from NULL.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ai_calls_guard_update() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o int; n int;
BEGIN
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.extraction_id IS DISTINCT FROM OLD.extraction_id
     OR NEW.subject_id IS DISTINCT FROM OLD.subject_id OR NEW.document_id IS DISTINCT FROM OLD.document_id
     OR NEW.started_by IS DISTINCT FROM OLD.started_by OR NEW.agent IS DISTINCT FROM OLD.agent
     OR NEW.attempt IS DISTINCT FROM OLD.attempt OR NEW.queued_at IS DISTINCT FROM OLD.queued_at THEN
    RAISE EXCEPTION 'ai_calls identity columns are immutable';
  END IF;
  o := CASE OLD.status WHEN 'queued' THEN 0 WHEN 'running' THEN 1 ELSE 2 END;
  n := CASE NEW.status WHEN 'queued' THEN 0 WHEN 'running' THEN 1 ELSE 2 END;
  IF o = 2 OR n < o THEN
    RAISE EXCEPTION 'ai_calls status only moves forward';
  END IF;
  IF (OLD.started_at IS NOT NULL AND NEW.started_at IS DISTINCT FROM OLD.started_at)
     OR (OLD.finished_at IS NOT NULL AND NEW.finished_at IS DISTINCT FROM OLD.finished_at)
     OR (OLD.duration_ms IS NOT NULL AND NEW.duration_ms IS DISTINCT FROM OLD.duration_ms)
     OR (OLD.failure_code IS NOT NULL AND NEW.failure_code IS DISTINCT FROM OLD.failure_code)
     OR (OLD.model IS NOT NULL AND NEW.model IS DISTINCT FROM OLD.model) THEN
    RAISE EXCEPTION 'ai_calls columns that are set once cannot change';
  END IF;
  RETURN NEW;
END
$$;
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ai_calls_guard_removal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF current_setting('ai.prune', true) IS DISTINCT FROM 'on'
     OR current_user::text <> (SELECT pg_get_userbyid(relowner)::text FROM pg_class WHERE oid = 'ai_calls'::regclass) THEN
    RAISE EXCEPTION 'ai_calls is append-only';
  END IF;
  IF TG_OP = 'DELETE' THEN
    RETURN OLD;
  END IF;
  RETURN NULL;
END
$$;
SQL);
        DB::unprepared('CREATE TRIGGER ai_calls_guard_update BEFORE UPDATE ON ai_calls FOR EACH ROW EXECUTE FUNCTION ai_calls_guard_update()');
        DB::unprepared('CREATE TRIGGER ai_calls_guard_delete BEFORE DELETE ON ai_calls FOR EACH ROW EXECUTE FUNCTION ai_calls_guard_removal()');
        DB::unprepared('CREATE TRIGGER ai_calls_guard_truncate BEFORE TRUNCATE ON ai_calls FOR EACH STATEMENT EXECUTE FUNCTION ai_calls_guard_removal()');

        // The only removal path for the app role: a definer function that opts in to the guard and deletes by age.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ai_calls_prune(months int DEFAULT 12) RETURNS bigint LANGUAGE plpgsql
  SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n bigint;
BEGIN
  PERFORM set_config('ai.prune', 'on', true);
  DELETE FROM ai_calls WHERE queued_at < now() - make_interval(months => months);
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END
$$;
SQL);
        DB::unprepared('REVOKE ALL ON FUNCTION ai_calls_prune(int) FROM PUBLIC');

        if ($role) {
            DB::unprepared("REVOKE DELETE, TRUNCATE ON ai_calls FROM {$this->appRole}");
            DB::unprepared("GRANT EXECUTE ON FUNCTION ai_calls_prune(int) TO {$this->appRole}");
        }

        Schema::create('ai_agent_status', function (Blueprint $table) {
            $table->string('agent', 20)->primary();
            $table->string('login_state', 12)->default('unknown');
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_auth_failure_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement("ALTER TABLE ai_agent_status ADD CONSTRAINT ai_agent_status_login_state_check CHECK (login_state IN ('logged_in', 'logged_out', 'wrong_mode', 'unknown'))");

        Schema::create('ai_notice_acks', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestampTz('acknowledged_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_notice_acks');
        Schema::dropIfExists('ai_agent_status');
        DB::unprepared('DROP TRIGGER IF EXISTS ai_calls_guard_update ON ai_calls');
        DB::unprepared('DROP TRIGGER IF EXISTS ai_calls_guard_delete ON ai_calls');
        DB::unprepared('DROP TRIGGER IF EXISTS ai_calls_guard_truncate ON ai_calls');
        DB::unprepared('DROP FUNCTION IF EXISTS ai_calls_prune(int)');
        DB::unprepared('DROP FUNCTION IF EXISTS ai_calls_guard_update()');
        DB::unprepared('DROP FUNCTION IF EXISTS ai_calls_guard_removal()');
        Schema::dropIfExists('ai_calls');
    }
};
