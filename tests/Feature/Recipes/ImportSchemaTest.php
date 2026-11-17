<?php

use App\Medical\Ai\FailureCode;
use App\Models\RecipeImport;
use App\Models\User;
use App\Recipes\Import\RecipeImportQuery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function risInsert(array $over = []): string
{
    $id = (string) Str::uuid();
    DB::table('recipe_imports')->insert(array_merge([
        'id' => $id, 'user_id' => 1, 'url' => 'https://a.example/x', 'url_hash' => hash('sha256', 'https://a.example/x'),
        'host' => 'a.example', 'status' => 'fetching', 'created_at' => now(), 'updated_at' => now(),
    ], $over));

    return $id;
}

function risCall(string $id, string $status = 'queued'): void
{
    DB::table('ai_calls')->insert([
        'extraction_id' => $id, 'kind' => 'recipe', 'subject_id' => null, 'document_id' => null, 'started_by' => 1,
        'agent' => 'codex', 'attempt' => 1, 'status' => $status, 'queued_at' => now(),
    ]);
}

it('refuses rows that break the status invariants', function (array $over) {
    expect(fn () => risInsert($over))->toThrow(QueryException::class);
})->with([
    'payload with failed' => [['status' => 'failed', 'failure_code' => 'cli_error', 'payload' => '{"a":1}']],
    'failure code without failed' => [['status' => 'ready', 'failure_code' => 'cli_error']],
    'failed without code' => [['status' => 'failed']],
    'source text with ready' => [['status' => 'ready', 'source_text' => 'x']],
    'source text with failed' => [['status' => 'failed', 'failure_code' => 'cli_error', 'source_text' => 'x']],
    'unknown status' => [['status' => 'parked']],
    'unknown fetch reason' => [['fetch_reason' => 'because']],
    'unknown cancel reason' => [['status' => 'cancelled', 'cancel_reason' => 'consent']],
    'unknown source kind' => [['source_kind' => 'pdf']],
]);

it('allows one active import per user and url, and a new one once it ends', function () {
    $id = risInsert();

    expect(fn () => DB::transaction(fn () => risInsert(['status' => 'queued'])))->toThrow(QueryException::class);
    // Another user, another url, and a finished row do not collide.
    risInsert(['user_id' => 2]);
    risInsert(['url_hash' => hash('sha256', 'other')]);
    DB::table('recipe_imports')->where('id', $id)->update(['status' => 'failed', 'failure_code' => 'fetch_failed']);
    expect(risInsert(['status' => 'ready']))->toBeString();
});

it('claims a queued import once and a second claim gets nothing', function () {
    $import = RecipeImport::factory()->queued()->create();
    risCall($import->id);
    $query = app(RecipeImportQuery::class);

    $claim = $query->claim($import->id);
    expect($claim)->toMatchArray(['user_id' => $import->user_id, 'source_kind' => 'text'])
        ->and($query->claim($import->id))->toBeNull()
        ->and(DB::table('recipe_imports')->where('id', $import->id)->value('status'))->toBe('running')
        ->and(DB::table('ai_calls')->where('extraction_id', $import->id)->value('status'))->toBe('running')
        ->and($query->claim('not-a-uuid'))->toBeNull();
});

it('finishReady stores the payload, clears the source text and closes the call row', function () {
    $import = RecipeImport::factory()->running()->create();
    risCall($import->id, 'running');

    $done = app(RecipeImportQuery::class)->finishReady($import->id, ['schema_version' => 1, 'is_recipe' => true], 'codex', 'gpt-x', 1200);

    $row = DB::table('recipe_imports')->where('id', $import->id)->first();
    expect($done)->toBeTrue()
        ->and($row->status)->toBe('ready')->and($row->source_text)->toBeNull()
        ->and(json_decode($row->payload, true))->toEqual(['schema_version' => 1, 'is_recipe' => true])
        ->and($row->agent)->toBe('codex')->and($row->model)->toBe('gpt-x');
    $call = DB::table('ai_calls')->where('extraction_id', $import->id)->first();
    expect($call->status)->toBe('succeeded')->and($call->kind)->toBe('recipe')->and($call->duration_ms)->toBe(1200);
});

it('never lets a late result overwrite a cancel request or a closed row', function () {
    $query = app(RecipeImportQuery::class);
    $cancelled = RecipeImport::factory()->running()->create(['cancel_requested_at' => now()]);
    $closed = RecipeImport::factory()->cancelled()->create();

    expect($query->finishReady($cancelled->id, ['is_recipe' => true], 'codex', 'm', 1))->toBeFalse()
        ->and($query->finishFailed($closed->id, FailureCode::CliError, 'm', 1))->toBeFalse()
        ->and($query->finishCancelled($closed->id, 'user', 'm', 1))->toBeFalse()
        ->and($query->abortReason($cancelled->id))->toBe('user')
        ->and($query->abortReason(RecipeImport::factory()->running()->create()->id))->toBeNull();
});

it('lists drafts without dismissed or old rows and scopes status to the owner', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $query = app(RecipeImportQuery::class);
    $ready = RecipeImport::factory()->ready()->create(['user_id' => $user->id]);
    RecipeImport::factory()->failed()->create(['user_id' => $user->id, 'dismissed_at' => now()]);
    $old = RecipeImport::factory()->ready()->create(['user_id' => $user->id]);
    DB::table('recipe_imports')->where('id', $old->id)->update(['created_at' => now()->subHours(25)]);
    RecipeImport::factory()->cancelled()->create(['user_id' => $user->id]);
    RecipeImport::factory()->ready()->create(['user_id' => $other->id]);

    expect(array_column($query->draftsFor($user), 'id'))->toBe([$ready->id])
        ->and($query->statusFor($user, $ready->id)['payload'])->toBe(['is_recipe' => true])
        ->and($query->statusFor($other, $ready->id))->toBeNull()
        ->and($query->activeFor($user))->toBe([]);
});
