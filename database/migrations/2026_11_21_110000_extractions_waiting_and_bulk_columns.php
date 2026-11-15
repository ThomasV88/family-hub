<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Waiting extractions (parked until an agent's usage limit resets), the 'decided' cancel reason, bulk run ids, one
 * call-log row per agent attempt, and bulk_run_id in the immutable identity of ai_calls. CREATE OR REPLACE keeps the
 * triggers and grants of ai_calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_status_check');
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_status_check CHECK (status IN ('queued', 'running', 'waiting', 'ready', 'failed', 'cancelled', 'superseded'))");
        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_cancel_reason_check');
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_cancel_reason_check CHECK (cancel_reason IS NULL OR cancel_reason IN ('user', 'consent', 'access', 'decided'))");
        DB::statement('ALTER TABLE medical_ai_extractions ADD COLUMN available_at timestamptz NULL, ADD COLUMN waiting_since timestamptz NULL, ADD COLUMN bulk_run_id uuid NULL');
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_waiting_check CHECK ((available_at IS NOT NULL) = (status = 'waiting'))");

        DB::statement('DROP INDEX medical_ai_extractions_one_active');
        DB::statement("CREATE UNIQUE INDEX medical_ai_extractions_one_active ON medical_ai_extractions (document_id) WHERE status IN ('queued', 'running', 'waiting')");
        DB::statement('DROP INDEX medical_ai_extractions_open_index');
        DB::statement("CREATE INDEX medical_ai_extractions_open_index ON medical_ai_extractions (status, queued_at) WHERE status IN ('queued', 'running', 'waiting')");
        DB::statement("CREATE INDEX medical_ai_extractions_waiting_due_index ON medical_ai_extractions (available_at) WHERE status = 'waiting'");

        DB::statement('ALTER TABLE ai_calls ADD COLUMN bulk_run_id uuid NULL');
        DB::statement('CREATE UNIQUE INDEX ai_calls_extraction_attempt_unique ON ai_calls (extraction_id, attempt)');
        DB::unprepared($this->guardFunction(true));
    }

    public function down(): void
    {
        // Both guards run before any change, so a refusal leaves the schema exactly as it was.
        $waiting = (int) DB::table('medical_ai_extractions')->where('status', 'waiting')->count();
        if ($waiting > 0) {
            throw new RuntimeException("Refusing to roll back: {$waiting} extraction row(s) are in status 'waiting'. Resolve them first (they are never rewritten silently).");
        }
        // 'decided' is a bulk-only cancel reason; the narrower check of the old schema would refuse these rows halfway through.
        $decided = (int) DB::table('medical_ai_extractions')->where('cancel_reason', 'decided')->count();
        if ($decided > 0) {
            throw new RuntimeException("Refusing to roll back: {$decided} extraction row(s) have cancel_reason 'decided'. Resolve them first (they are never rewritten silently).");
        }

        DB::unprepared($this->guardFunction(false));
        DB::statement('DROP INDEX IF EXISTS ai_calls_extraction_attempt_unique');
        DB::statement('ALTER TABLE ai_calls DROP COLUMN bulk_run_id');

        DB::statement('DROP INDEX medical_ai_extractions_waiting_due_index');
        DB::statement('DROP INDEX medical_ai_extractions_open_index');
        DB::statement("CREATE INDEX medical_ai_extractions_open_index ON medical_ai_extractions (status, queued_at) WHERE status IN ('queued', 'running')");
        DB::statement('DROP INDEX medical_ai_extractions_one_active');
        DB::statement("CREATE UNIQUE INDEX medical_ai_extractions_one_active ON medical_ai_extractions (document_id) WHERE status IN ('queued', 'running')");

        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_waiting_check');
        DB::statement('ALTER TABLE medical_ai_extractions DROP COLUMN available_at, DROP COLUMN waiting_since, DROP COLUMN bulk_run_id');
        // No 'decided' row can exist here: the guard above refused to start while there was one.
        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_cancel_reason_check');
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_cancel_reason_check CHECK (cancel_reason IS NULL OR cancel_reason IN ('user', 'consent', 'access'))");
        DB::statement('ALTER TABLE medical_ai_extractions DROP CONSTRAINT medical_ai_extractions_status_check');
        DB::statement("ALTER TABLE medical_ai_extractions ADD CONSTRAINT medical_ai_extractions_status_check CHECK (status IN ('queued', 'running', 'ready', 'failed', 'cancelled', 'superseded'))");
    }

    /** The Phase 8 body, plus bulk_run_id in the immutable identity list when $withBulk. */
    private function guardFunction(bool $withBulk): string
    {
        $bulk = $withBulk ? "\n     OR NEW.bulk_run_id IS DISTINCT FROM OLD.bulk_run_id" : '';

        return <<<SQL
CREATE OR REPLACE FUNCTION ai_calls_guard_update() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE o int; n int;
BEGIN
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.extraction_id IS DISTINCT FROM OLD.extraction_id
     OR NEW.subject_id IS DISTINCT FROM OLD.subject_id OR NEW.document_id IS DISTINCT FROM OLD.document_id
     OR NEW.started_by IS DISTINCT FROM OLD.started_by OR NEW.agent IS DISTINCT FROM OLD.agent
     OR NEW.attempt IS DISTINCT FROM OLD.attempt OR NEW.queued_at IS DISTINCT FROM OLD.queued_at{$bulk} THEN
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
\$\$;
SQL;
    }
};
