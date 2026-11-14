<?php

use App\Models\AiCall;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The tests connect as the table owner, who cannot SET ROLE fh_app. The grants and guards are therefore proven on two
 * extra connections outside the RefreshDatabase transaction (the real runtime role and a fresh owner connection), and
 * the rows they commit are removed again through the owner opt-in in afterEach.
 */
function aiConn(string $role): Connection
{
    $name = 'ai_'.$role;
    if (! config()->has('database.connections.'.$name)) {
        $base = config('database.connections.pgsql');
        $creds = $role === 'app' ? ['username' => 'fh_app', 'password' => env('FH_APP_PASSWORD', 'fh_app_dev')] : [];
        config(['database.connections.'.$name => $creds + $base]);
    }

    return DB::connection($name);
}

/** Runs $fn in its own transaction (a refused statement must not poison later ones); returns the error message or null. */
function aiCallError(callable $fn, string $role = 'app'): ?string
{
    try {
        aiConn($role)->transaction($fn);
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return null;
}

function callRow(array $attrs = [], string $role = 'app'): int
{
    return (int) aiConn($role)->table('ai_calls')->insertGetId($attrs + [
        'extraction_id' => (string) Str::uuid(), 'subject_id' => (string) Str::uuid(), 'document_id' => (string) Str::uuid(),
        'started_by' => 1, 'agent' => 'codex', 'status' => 'queued',
    ]);
}

afterEach(function () {
    $owner = aiConn('owner');
    $owner->transaction(function () use ($owner) {
        $owner->statement("select set_config('ai.prune', 'on', true)");
        $owner->table('ai_calls')->delete();
    });
    // Each test boots its own app and so opens its own extra connections; close them now instead of waiting for the
    // garbage collector, or a long run exhausts the dev Postgres (max_connections=20).
    DB::purge('ai_owner');
    DB::purge('ai_app');
});

it('lets fh_app insert and advance the status forward', function () {
    $db = aiConn('app');
    $id = callRow();
    $db->table('ai_calls')->where('id', $id)->update(['status' => 'running', 'started_at' => now()]);
    $db->table('ai_calls')->where('id', $id)->update(['status' => 'succeeded', 'finished_at' => now(), 'duration_ms' => 5, 'model' => 'default']);

    expect($db->table('ai_calls')->where('id', $id)->value('status'))->toBe('succeeded');
});

it('refuses identity changes, going back, and any change to a terminal row', function () {
    $db = aiConn('app');
    $id = callRow();
    $row = fn () => $db->table('ai_calls')->where('id', $id);

    expect(aiCallError(fn () => $row()->update(['subject_id' => (string) Str::uuid()])))->toContain('identity')
        ->and(aiCallError(fn () => $row()->update(['started_by' => 2])))->toContain('identity')
        ->and(aiCallError(fn () => $row()->update(['queued_at' => now()->subDay()])))->toContain('identity')
        ->and(aiCallError(fn () => $row()->update(['attempt' => 2])))->toContain('identity');

    $row()->update(['status' => 'running']);
    expect(aiCallError(fn () => $row()->update(['status' => 'queued'])))->toContain('forward');

    $row()->update(['finished_at' => now()]);
    expect(aiCallError(fn () => $row()->update(['finished_at' => now()->addMinute()])))->toContain('set once');

    $row()->update(['status' => 'failed', 'failure_code' => 'timeout']);
    expect(aiCallError(fn () => $row()->update(['failure_code' => 'other'])))->toContain('forward')
        ->and(aiCallError(fn () => $row()->update(['status' => 'succeeded'])))->toContain('forward');
});

it('sets model exactly once', function () {
    $db = aiConn('app');
    $id = callRow();
    expect($db->table('ai_calls')->where('id', $id)->value('model'))->toBeNull();

    $db->table('ai_calls')->where('id', $id)->update(['status' => 'succeeded', 'model' => 'gpt-x']);
    expect($db->table('ai_calls')->where('id', $id)->value('model'))->toBe('gpt-x');

    $running = callRow(['status' => 'running', 'model' => 'gpt-x']);
    expect(aiCallError(fn () => $db->table('ai_calls')->where('id', $running)->update(['model' => 'gpt-y'])))->toContain('set once')
        ->and(aiCallError(fn () => $db->table('ai_calls')->where('id', $running)->update(['model' => null])))->toContain('set once');
});

it('refuses DELETE and TRUNCATE for fh_app and for the owner without the opt-in', function () {
    $app = aiConn('app');
    callRow();

    expect(aiCallError(fn () => $app->table('ai_calls')->delete()))->toContain('permission denied')
        ->and(aiCallError(fn () => $app->unprepared('truncate ai_calls')))->toContain('permission denied')
        ->and(aiCallError(fn () => aiConn('owner')->table('ai_calls')->delete(), 'owner'))->toContain('append-only')
        ->and(aiCallError(fn () => aiConn('owner')->unprepared('truncate ai_calls'), 'owner'))->toContain('append-only')
        ->and($app->table('ai_calls')->count())->toBe(1);
});

it('prunes only rows older than 12 months through the definer function', function () {
    $app = aiConn('app');
    callRow(['queued_at' => now()->subMonths(14)]);
    $keep = callRow(['queued_at' => now()->subMonth()]);

    $n = $app->selectOne('select ai_calls_prune(12) as n')->n;

    expect((int) $n)->toBe(1)
        ->and($app->table('ai_calls')->pluck('id')->all())->toBe([$keep]);
});

it('refuses a retention below the floor and deletes nothing', function (string $months) {
    $app = aiConn('app');
    $recent = callRow(['queued_at' => now()->subDay()]);
    $old = callRow(['queued_at' => now()->subMonths(14)]);

    foreach ([$months, 'null'] as $value) {
        expect(aiCallError(fn () => aiConn('app')->selectOne("select ai_calls_prune({$value}::int) as n")))->toContain('retention floor');
    }

    expect($app->table('ai_calls')->pluck('id')->sort()->values()->all())->toBe(collect([$recent, $old])->sort()->values()->all());
})->with(['0', '-5', '11']);

it('pins the search path of the prune function', function () {
    $config = DB::selectOne("select proconfig from pg_proc where proname = 'ai_calls_prune'")->proconfig;

    expect($config)->toContain('search_path=public, pg_temp');
});

it('prints only the count from ai:prune-calls', function () {
    callRow(['queued_at' => now()->subMonths(14)]);
    callRow(['queued_at' => now()->subMonths(13)]);

    // The command runs on a connection outside the test transaction, so the afterEach cleanup cannot block on its locks.
    aiConn('app');
    config(['database.default' => 'ai_app']);
    $code = Artisan::call('ai:prune-calls');
    config(['database.default' => 'pgsql']);

    expect($code)->toBe(0)->and(trim(Artisan::output()))->toBe('Pruned 2 AI call rows.');
});

it('has no column that could hold content', function () {
    $cols = DB::table('information_schema.columns')->where('table_name', 'ai_calls')->where('table_schema', 'public')->pluck('column_name')->sort()->values()->all();

    expect($cols)->toBe(collect([
        'id', 'extraction_id', 'subject_id', 'document_id', 'started_by', 'agent', 'model', 'attempt', 'status',
        'failure_code', 'queued_at', 'started_at', 'finished_at', 'duration_ms',
    ])->sort()->values()->all());
});

it('stores agent status and notice acks, and deletes the ack with the user', function () {
    DB::table('ai_agent_status')->insert(['agent' => 'codex']);
    expect(DB::table('ai_agent_status')->value('login_state'))->toBe('unknown');

    $refused = false;
    try {
        DB::transaction(fn () => DB::table('ai_agent_status')->where('agent', 'codex')->update(['login_state' => 'bogus']));
    } catch (QueryException) {
        $refused = true;
    }
    expect($refused)->toBeTrue();

    $user = User::factory()->create();
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id]);
    expect(DB::table('ai_notice_acks')->count())->toBe(1);

    $user->delete();

    expect(DB::table('ai_notice_acks')->count())->toBe(0);
});

it('builds call rows with the factory', function () {
    $call = AiCall::factory()->running()->create();

    expect($call->fresh()->status)->toBe('running');
});
