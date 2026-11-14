<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The prune function is the one removal path of the runtime role, so it must not be able to remove recent history:
 * ai_calls_prune(0) or a negative value used to delete every row. A retention floor of 12 months is enforced inside
 * the definer function (the config value may only raise it). Grants are kept by CREATE OR REPLACE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ai_calls_prune(months int DEFAULT 12) RETURNS bigint LANGUAGE plpgsql
  SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n bigint;
BEGIN
  IF months IS NULL OR months < 12 THEN
    RAISE EXCEPTION 'ai_calls retention floor is 12 months';
  END IF;
  PERFORM set_config('ai.prune', 'on', true);
  DELETE FROM public.ai_calls WHERE queued_at < now() - make_interval(months => months);
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END
$$;
SQL);
    }

    public function down(): void
    {
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
    }
};
