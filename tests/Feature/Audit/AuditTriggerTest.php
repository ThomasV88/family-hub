<?php

use App\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('raises on any UPDATE of an audit row', function () {
    DB::table('audit_logs')->insert(['event' => 'login_success']);

    expect(fn () => DB::table('audit_logs')->update(['event' => 'tampered']))->toThrow(QueryException::class, 'append-only');
});

it('refuses DELETE and TRUNCATE outside the audit:prune opt-in, even for the table owner', function () {
    DB::table('audit_logs')->insert(['event' => 'login_success']);

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->delete()))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::unprepared('truncate audit_logs')))->toThrow(QueryException::class, 'append-only');
    expect(DB::table('audit_logs')->count())->toBe(1);
});

it('refuses bulk model deletes and updates', function () {
    AuditEntry::create(['event' => 'login_success']);

    expect(fn () => DB::transaction(fn () => AuditEntry::query()->delete()))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => AuditEntry::query()->update(['event' => 'x'])))->toThrow(QueryException::class, 'append-only');
});

function auditMigration(): object
{
    return require database_path('migrations/2026_11_13_120000_create_audit_logs_table.php');
}

it('fails the migration when the app role is missing in a production-like environment', function () {
    DB::unprepared('drop table audit_logs');
    $migration = auditMigration();
    $migration->appRole = 'fh_role_that_does_not_exist';
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'does not exist');
});

it('skips the revoke quietly when the app role is missing in a local environment', function () {
    DB::unprepared('drop table audit_logs');
    $migration = auditMigration();
    $migration->appRole = 'fh_role_that_does_not_exist';
    app()->detectEnvironment(fn () => 'local');

    $migration->up();

    expect(DB::selectOne("select count(*) as c from pg_trigger where tgrelid = 'audit_logs'::regclass and not tgisinternal")->c)->toBe(3);
});
