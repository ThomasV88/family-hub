<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The call log also records recipe imports: a kind column (immutable, like the rest of the identity) and nullable
 * subject and document ids under a CHECK that ties them to the kind. Still content-free. CREATE OR REPLACE keeps the
 * triggers, grants and the prune function of ai_calls untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE ai_calls ADD COLUMN kind varchar(10) NOT NULL DEFAULT 'medical'");
        DB::statement("ALTER TABLE ai_calls ADD CONSTRAINT ai_calls_kind_check CHECK (kind IN ('medical', 'recipe'))");
        DB::statement('ALTER TABLE ai_calls ALTER COLUMN subject_id DROP NOT NULL');
        DB::statement('ALTER TABLE ai_calls ALTER COLUMN document_id DROP NOT NULL');
        DB::statement("ALTER TABLE ai_calls ADD CONSTRAINT ai_calls_kind_subject_check CHECK (kind = 'medical' AND subject_id IS NOT NULL AND document_id IS NOT NULL OR kind = 'recipe' AND subject_id IS NULL AND document_id IS NULL)");
        DB::unprepared($this->guardFunction(true));
    }

    public function down(): void
    {
        $recipe = (int) DB::table('ai_calls')->where('kind', 'recipe')->count();
        if ($recipe > 0) {
            throw new RuntimeException("Refusing to roll back: {$recipe} ai_calls row(s) have kind 'recipe'. They are never deleted or rewritten silently.");
        }

        DB::unprepared($this->guardFunction(false));
        DB::statement('ALTER TABLE ai_calls DROP CONSTRAINT ai_calls_kind_subject_check');
        DB::statement('ALTER TABLE ai_calls ALTER COLUMN subject_id SET NOT NULL');
        DB::statement('ALTER TABLE ai_calls ALTER COLUMN document_id SET NOT NULL');
        DB::statement('ALTER TABLE ai_calls DROP CONSTRAINT ai_calls_kind_check');
        DB::statement('ALTER TABLE ai_calls DROP COLUMN kind');
    }

    /** The Phase 9 body (2026_11_21_110000), plus kind in the immutable identity list when $withKind. */
    private function guardFunction(bool $withKind): string
    {
        $kind = $withKind ? "\n     OR NEW.kind IS DISTINCT FROM OLD.kind" : '';

        return <<<SQL
CREATE OR REPLACE FUNCTION ai_calls_guard_update() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE o int; n int;
BEGIN
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.extraction_id IS DISTINCT FROM OLD.extraction_id
     OR NEW.subject_id IS DISTINCT FROM OLD.subject_id OR NEW.document_id IS DISTINCT FROM OLD.document_id
     OR NEW.started_by IS DISTINCT FROM OLD.started_by OR NEW.agent IS DISTINCT FROM OLD.agent
     OR NEW.attempt IS DISTINCT FROM OLD.attempt OR NEW.queued_at IS DISTINCT FROM OLD.queued_at
     OR NEW.bulk_run_id IS DISTINCT FROM OLD.bulk_run_id{$kind} THEN
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
