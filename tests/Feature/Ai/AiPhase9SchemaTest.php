<?php

use App\Models\AiBulkRun;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Runs $fn in a savepoint so a constraint failure does not abort the test transaction; true when refused. */
function p9Refused(callable $fn): bool
{
    try {
        DB::transaction($fn);
    } catch (QueryException) {
        return true;
    }

    return false;
}

function p9Doc(): MedicalDocument
{
    return MedicalDocument::factory()->forSubject(MedicalSubject::factory()->create())->create();
}

function p9Setting(string $model): bool
{
    return p9Refused(fn () => DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['model' => $model]));
}

it('seeds codex on at position 1 and copilot off at position 2', function () {
    $rows = DB::table('ai_agent_settings')->orderBy('position')->get();

    expect($rows->pluck('agent')->all())->toBe(['codex', 'copilot'])
        ->and($rows->pluck('enabled')->all())->toBe([true, false])
        ->and($rows->pluck('position')->all())->toBe([1, 2])
        ->and($rows->pluck('model')->filter()->all())->toBe([]);
});

it('refuses an unsafe model id and accepts real ones', function () {
    foreach (['bad model', '-rf', 'a/b', str_repeat('a', 61), ''] as $bad) {
        expect(p9Setting($bad))->toBeTrue("model {$bad}");
    }
    foreach (['gpt-5.6-sol', 'claude-haiku-4.5', 'o4:mini', str_repeat('a', 60)] as $good) {
        expect(p9Setting($good))->toBeFalse("model {$good}");
    }
});

it('refuses an unknown agent, a bad position and a duplicate position at commit but allows a swap', function () {
    expect(p9Refused(fn () => DB::table('ai_agent_settings')->insert(['agent' => 'claude', 'position' => 3])))->toBeTrue()
        ->and(p9Refused(fn () => DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['position' => 10])))->toBeTrue();

    // Deferred unique: the duplicate is only detected when the constraint is checked.
    expect(p9Refused(function () {
        DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['position' => 1]);
        DB::statement('SET CONSTRAINTS ai_agent_settings_position_unique IMMEDIATE');
    }))->toBeTrue();

    DB::transaction(function () {
        DB::table('ai_agent_settings')->where('agent', 'codex')->update(['position' => 2]);
        DB::table('ai_agent_settings')->where('agent', 'copilot')->update(['position' => 1]);
        DB::statement('SET CONSTRAINTS ai_agent_settings_position_unique IMMEDIATE');
    });

    expect(DB::table('ai_agent_settings')->where('agent', 'copilot')->value('position'))->toBe(1);
});

it('adds cooldown columns to the agent status and a provider set to notice acks', function () {
    DB::table('ai_agent_status')->insert(['agent' => 'codex']);
    $status = DB::table('ai_agent_status')->first();

    expect($status->cooldown_until)->toBeNull()
        ->and($status->quota_hits)->toBe(0)
        ->and($status->last_quota_at)->toBeNull();

    $user = User::factory()->create();
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id]);

    expect(DB::table('ai_notice_acks')->value('providers'))->toBe('codex');
});

it('refuses a waiting extraction without a resume time and a queued one with it', function () {
    $doc = p9Doc();

    MedicalAiExtraction::factory()->forDocument($doc)->waiting()->create();
    $other = p9Doc();

    expect(p9Refused(fn () => MedicalAiExtraction::factory()->forDocument($other)->create(['status' => 'waiting'])))->toBeTrue()
        ->and(p9Refused(fn () => MedicalAiExtraction::factory()->forDocument($other)->create(['available_at' => now()])))->toBeTrue();
});

it('lets a waiting run block a second active run but coexist with a ready one', function () {
    $doc = p9Doc();
    MedicalAiExtraction::factory()->forDocument($doc)->waiting()->create();

    foreach (['queued', 'running'] as $status) {
        $state = $status === 'running' ? MedicalAiExtraction::factory()->forDocument($doc)->running() : MedicalAiExtraction::factory()->forDocument($doc);
        $error = null;
        try {
            DB::transaction(fn () => $state->create());
        } catch (QueryException $e) {
            $error = $e->getCode();
        }
        expect($error)->toBe('23505');
    }
    expect(p9Refused(fn () => MedicalAiExtraction::factory()->forDocument($doc)->waiting()->create()))->toBeTrue();

    MedicalAiExtraction::factory()->forDocument($doc)->ready()->create();
    expect(MedicalAiExtraction::where('document_id', $doc->id)->count())->toBe(2);
});

it('accepts the decided cancel reason and a bulk run id on the factory', function () {
    $run = (string) Str::uuid();
    $row = MedicalAiExtraction::factory()->forDocument(p9Doc())->bulk($run)->cancelled('decided')->create();

    expect($row->fresh()->cancel_reason)->toBe('decided')
        ->and(DB::table('medical_ai_extractions')->where('id', $row->id)->value('bulk_run_id'))->toBe($run);
});

it('accepts a bulk run id on ai_calls and refuses a second row for the same attempt', function () {
    $extraction = (string) Str::uuid();
    $base = ['extraction_id' => $extraction, 'subject_id' => (string) Str::uuid(), 'document_id' => (string) Str::uuid(),
        'started_by' => 1, 'agent' => 'codex', 'status' => 'queued'];
    DB::table('ai_calls')->insert($base + ['bulk_run_id' => (string) Str::uuid()]);

    expect(p9Refused(fn () => DB::table('ai_calls')->insert($base)))->toBeTrue();

    DB::table('ai_calls')->insert($base + ['attempt' => 2, 'agent' => 'copilot']);
    expect(DB::table('ai_calls')->where('extraction_id', $extraction)->count())->toBe(2);
});

it('rolls the waiting migration back only when no row is waiting', function () {
    $migration = require database_path('migrations/2026_11_21_110000_extractions_waiting_and_bulk_columns.php');
    MedicalAiExtraction::factory()->forDocument(p9Doc())->waiting()->create();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, "1 extraction row(s) are in status 'waiting'");

    // Postgres DDL is transactional: roll the whole thing back inside a savepoint and check the old rules came back.
    DB::table('medical_ai_extractions')->where('status', 'waiting')->delete();
    DB::beginTransaction();
    $migration->down();
    $doc = p9Doc();
    $statusRefused = p9Refused(fn () => MedicalAiExtraction::factory()->forDocument($doc)->create(['status' => 'waiting']));
    $columns = DB::table('information_schema.columns')->where('table_name', 'medical_ai_extractions')->pluck('column_name')->all();
    DB::rollBack();

    expect($statusRefused)->toBeTrue()
        ->and($columns)->not->toContain('available_at')
        ->and(DB::table('information_schema.columns')->where('table_name', 'medical_ai_extractions')->pluck('column_name')->all())->toContain('available_at');
});

it('refuses the waiting migration rollback while a decided row exists, before changing anything', function () {
    $migration = require database_path('migrations/2026_11_21_110000_extractions_waiting_and_bulk_columns.php');
    $run = (string) Str::uuid();
    MedicalAiExtraction::factory()->forDocument(p9Doc())->bulk($run)->cancelled('decided')->create();
    $columnsBefore = DB::table('information_schema.columns')->where('table_name', 'medical_ai_extractions')->pluck('column_name')->sort()->values()->all();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, "1 extraction row(s) have cancel_reason 'decided'");

    // Nothing was touched: same columns, and the new rules still hold.
    expect(DB::table('information_schema.columns')->where('table_name', 'medical_ai_extractions')->pluck('column_name')->sort()->values()->all())->toBe($columnsBefore)
        ->and(MedicalAiExtraction::factory()->forDocument(p9Doc())->waiting()->create()->exists)->toBeTrue();

    // Once the decided row is resolved the rollback runs through.
    DB::table('medical_ai_extractions')->where('cancel_reason', 'decided')->delete();
    DB::beginTransaction();
    DB::table('medical_ai_extractions')->where('status', 'waiting')->delete();
    $migration->down();
    DB::rollBack();
});

it('allows one open bulk run and a completed run beside a new active one', function () {
    AiBulkRun::factory()->active()->create();

    expect(p9Refused(fn () => AiBulkRun::factory()->active()->create()))->toBeTrue()
        ->and(p9Refused(fn () => AiBulkRun::factory()->paused()->create()))->toBeTrue();

    AiBulkRun::factory()->completed()->create();
    AiBulkRun::factory()->cancelled()->create();

    expect(AiBulkRun::count())->toBe(3);
});

it('refuses bad bulk scope, status and daily cap', function () {
    expect(p9Refused(fn () => AiBulkRun::factory()->create(['scope' => 'everything', 'status' => 'completed'])))->toBeTrue()
        ->and(p9Refused(fn () => AiBulkRun::factory()->create(['status' => 'running'])))->toBeTrue()
        ->and(p9Refused(fn () => AiBulkRun::factory()->completed()->create(['daily_cap' => 0])))->toBeTrue()
        ->and(p9Refused(fn () => AiBulkRun::factory()->completed()->create(['daily_cap' => 101])))->toBeTrue();

    $run = AiBulkRun::factory()->completed()->create(['daily_cap' => 100]);
    expect($run->fresh()->blocked_since)->toBeNull();
});

it('refuses a duplicate bulk item and an unknown outcome', function () {
    $run = (string) Str::uuid();
    $doc = (string) Str::uuid();
    DB::table('ai_bulk_run_items')->insert(['bulk_run_id' => $run, 'document_id' => $doc]);

    expect(p9Refused(fn () => DB::table('ai_bulk_run_items')->insert(['bulk_run_id' => $run, 'document_id' => $doc])))->toBeTrue()
        ->and(p9Refused(fn () => DB::table('ai_bulk_run_items')->insert(['bulk_run_id' => $run, 'document_id' => (string) Str::uuid(), 'outcome' => 'bogus'])))->toBeTrue();

    foreach (['read', 'failed', 'cancelled', 'skipped', 'gone'] as $outcome) {
        DB::table('ai_bulk_run_items')->insert(['bulk_run_id' => $run, 'document_id' => (string) Str::uuid(), 'outcome' => $outcome]);
    }
    expect(DB::table('ai_bulk_run_items')->count())->toBe(6);
});

it('keeps the bulk tables free of any column that could hold content', function () {
    $cols = fn (string $table) => DB::table('information_schema.columns')->where('table_name', $table)->where('table_schema', 'public')
        ->pluck('column_name')->sort()->values()->all();
    $sorted = fn (array $c) => collect($c)->sort()->values()->all();

    expect($cols('ai_bulk_dry_runs'))->toBe($sorted(['id', 'requested_by', 'scope', 'daily_cap', 'eligible', 'skipped_consent',
        'skipped_unsupported', 'skipped_decided', 'skipped_active', 'created_at', 'consumed_at']))
        ->and($cols('ai_bulk_runs'))->toBe($sorted(['id', 'started_by', 'scope', 'daily_cap', 'status', 'dry_run_id', 'eligible_total',
            'skipped_count', 'last_enqueued_at', 'blocked_since', 'created_at', 'paused_at', 'finished_at', 'updated_at']))
        ->and($cols('ai_bulk_run_items'))->toBe($sorted(['bulk_run_id', 'document_id', 'extraction_id', 'enqueued_at', 'outcome']))
        ->and($cols('ai_agent_settings'))->toBe($sorted(['agent', 'enabled', 'position', 'model', 'updated_by', 'updated_at']));
});
