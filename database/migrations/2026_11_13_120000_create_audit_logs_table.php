<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            // Plain columns on purpose: referential actions would UPDATE or DELETE rows, and history must outlive users.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 120)->nullable();
            $table->string('event', 40);
            $table->string('module', 20)->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('target_label', 120)->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->jsonb('meta')->nullable();

            $table->index('occurred_at');
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'fh_app') THEN
        REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM fh_app;
    END IF;
END
$$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_logs_no_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs is append-only';
END
$$;
SQL);

        DB::unprepared('CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_no_update()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_no_update()');
        Schema::dropIfExists('audit_logs');
    }
};
