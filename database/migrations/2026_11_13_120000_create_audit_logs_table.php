<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Runtime role that must never hold UPDATE/DELETE/TRUNCATE on audit_logs. */
    public string $appRole = 'fh_app';

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

        // Layer 1 (privileges): the runtime role may only INSERT and SELECT. Where that role cannot be
        // found in a production-like environment the migration fails instead of silently skipping,
        // because without it nothing but the triggers below stands between the app and the history.
        $role = DB::selectOne('select exists (select from pg_roles where rolname = ?) as e', [$this->appRole])->e;
        if ($role) {
            DB::unprepared("REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM {$this->appRole}");
        } elseif (app()->environment(['production', 'staging'])) {
            throw new RuntimeException("Role {$this->appRole} does not exist: refusing to create audit_logs without revoking UPDATE/DELETE/TRUNCATE from it. Create the roles first (docker/postgres/init/01-roles.sh).");
        }

        // Layer 2 (triggers, hold even if the app connects as the table owner): UPDATE is always refused.
        // DELETE and TRUNCATE are refused unless the current role is the table owner AND the transaction
        // opted in with `SET LOCAL audit.prune = 'on'` (only audit:prune does). A table owner or superuser
        // can still drop the triggers: this is tamper-resistance against the app, not against the DB owner.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_logs_no_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs is append-only';
END
$$;
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_logs_guard_removal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF current_setting('audit.prune', true) IS DISTINCT FROM 'on'
       OR current_user::text <> (SELECT pg_get_userbyid(relowner)::text FROM pg_class WHERE oid = 'audit_logs'::regclass) THEN
        RAISE EXCEPTION 'audit_logs is append-only';
    END IF;
    RETURN NULL;
END
$$;
SQL);

        DB::unprepared('CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_no_update()');
        DB::unprepared('CREATE TRIGGER audit_logs_guard_delete BEFORE DELETE ON audit_logs FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_guard_removal()');
        DB::unprepared('CREATE TRIGGER audit_logs_guard_truncate BEFORE TRUNCATE ON audit_logs FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_guard_removal()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_guard_delete ON audit_logs');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_guard_truncate ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_no_update()');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_guard_removal()');
        Schema::dropIfExists('audit_logs');
    }
};
