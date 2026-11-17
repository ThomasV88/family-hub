<?php

use App\Models\RecipeImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecipeImportFixtures as F;

uses(RefreshDatabase::class);

/** Moves the clocks of an import (and the given call row) into the past. */
function imtAge(RecipeImport $import, int $minutes): void
{
    $then = now()->subMinutes($minutes);
    DB::table('recipe_imports')->where('id', $import->id)->update(['created_at' => $then, 'queued_at' => DB::raw('case when queued_at is null then null else \''.$then->toDateTimeString().'\'::timestamptz end'), 'started_at' => DB::raw('case when started_at is null then null else \''.$then->toDateTimeString().'\'::timestamptz end')]);
}

function imtCall(RecipeImport $import, string $status): void
{
    DB::table('ai_calls')->insert([
        'extraction_id' => $import->id, 'kind' => 'recipe', 'subject_id' => null, 'document_id' => null, 'started_by' => $import->user_id,
        'agent' => 'codex', 'attempt' => 1, 'status' => $status, 'queued_at' => now(), 'started_at' => $status === 'running' ? now() : null,
    ]);
}

function imtFactory(string $state, string $url)
{
    $factory = RecipeImport::factory()->forUrl($url);

    return $state === 'fetching' ? $factory : $factory->{$state}();
}

function imtRow(RecipeImport $import): ?object
{
    return DB::table('recipe_imports')->where('id', $import->id)->first();
}

it('reaps stuck imports, clears their text and closes the open call row', function () {
    $user = F::member();
    $make = fn ($state, int $minutes, ?string $call = null) => tap(imtFactory($state, 'https://recipes.example/'.uniqid())->create(['user_id' => $user->id]),
        function ($i) use ($minutes, $call) {
            imtAge($i, $minutes);
            if ($call !== null) {
                imtCall($i, $call);
            }
        });
    $oldRunning = $make('running', 16, 'running');
    $freshRunning = $make('running', 14, 'running');
    $oldFetching = $make('fetching', 6);
    $freshFetching = $make('fetching', 4);
    $oldQueued = $make('queued', 31, 'queued');
    $freshQueued = $make('queued', 29, 'queued');
    $ready = $make('ready', 600);

    expect(Artisan::call('recipes:reap-imports'))->toBe(0)->and(trim(Artisan::output()))->toBe('3');

    $row = imtRow($oldRunning);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('worker_lost')->and($row->source_text)->toBeNull()->and($row->finished_at)->not->toBeNull();
    $call = DB::table('ai_calls')->where('extraction_id', $oldRunning->id)->first();
    expect($call->status)->toBe('failed')->and($call->failure_code)->toBe('worker_lost')->and($call->finished_at)->not->toBeNull();

    $row = imtRow($oldFetching);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('fetch_failed')->and($row->fetch_reason)->toBe('network')->and($row->source_text)->toBeNull();

    $row = imtRow($oldQueued);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('worker_lost')->and($row->source_text)->toBeNull()
        ->and(DB::table('ai_calls')->where('extraction_id', $oldQueued->id)->value('status'))->toBe('failed');

    foreach ([$freshRunning, $freshFetching, $freshQueued] as $untouched) {
        expect(imtRow($untouched)->status)->toBe($untouched->status);
    }
    expect(DB::table('ai_calls')->where('extraction_id', $freshRunning->id)->value('status'))->toBe('running')
        ->and(imtRow($ready)->status)->toBe('ready');
});

it('expires abandoned drafts after 24 hours and deletes finished rows after 7 days, keeping ai_calls', function () {
    $user = F::member();
    $age = fn (RecipeImport $i, int $hours) => DB::table('recipe_imports')->where('id', $i->id)->update(['created_at' => now()->subHours($hours)]);
    $make = fn ($state, int $hours) => tap(imtFactory($state, 'https://recipes.example/'.uniqid())->create(['user_id' => $user->id]), fn ($i) => $age($i, $hours));

    $oldReady = $make('ready', 25);
    $freshReady = $make('ready', 23);
    $oldFailed = $make('failed', 24 * 7 + 1);
    $freshFailed = $make('failed', 24 * 6);
    $oldCancelled = $make('cancelled', 24 * 8);
    $oldUsed = $make('ready', 24 * 9);
    DB::table('recipe_imports')->where('id', $oldUsed->id)->update(['status' => 'used', 'payload' => null]);
    $oldActive = $make('running', 24 * 9);
    imtCall($oldFailed, 'running');
    DB::table('ai_calls')->where('extraction_id', $oldFailed->id)->update(['status' => 'failed', 'failure_code' => 'cli_error', 'model' => 'default', 'finished_at' => now()]);

    expect(Artisan::call('recipes:prune-imports'))->toBe(0);

    $row = imtRow($oldReady);
    expect($row->status)->toBe('cancelled')->and($row->payload)->toBeNull();
    expect(imtRow($freshReady)->status)->toBe('ready')->and(imtRow($freshReady)->payload)->not->toBeNull()
        ->and(imtRow($freshFailed))->not->toBeNull()
        ->and(imtRow($oldFailed))->toBeNull()->and(imtRow($oldCancelled))->toBeNull()->and(imtRow($oldUsed))->toBeNull()
        ->and(imtRow($oldActive))->not->toBeNull()
        ->and(DB::table('ai_calls')->where('extraction_id', $oldFailed->id)->count())->toBe(1);
});

it('never touches medical tables', function () {
    $before = DB::table('medical_ai_extractions')->count();

    Artisan::call('recipes:reap-imports');
    Artisan::call('recipes:prune-imports');

    expect(DB::table('medical_ai_extractions')->count())->toBe($before);
});
