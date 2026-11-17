<?php

use App\Access\AccessLevel;
use App\Medical\Queries\AiBulkEligibility;
use App\Medical\Queries\AiJobQuery;
use App\Medical\Queries\AiReviewQuery;
use App\Medical\Queries\AiRunQuery;
use App\Medical\Viewer;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, subject: mixed, doc: MedicalDocument}
 */
function txSavedSetup(AccessLevel $level = AccessLevel::Edit): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, $level);
    $subject = MedicalFixtures::subject(['ai_consent' => true], [[$user, $level]]);
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Scan']);

    return compact('user', 'subject', 'doc');
}

function txSavedRun(array $s, array $attrs = []): MedicalAiExtraction
{
    $run = MedicalAiExtraction::factory()->forDocument($s['doc'])->create(['started_by' => $s['user']->id] + $attrs);
    AiCall::factory()->create(['extraction_id' => $run->id, 'subject_id' => $s['subject']->id, 'document_id' => $s['doc']->id, 'started_by' => $s['user']->id, 'status' => 'running']);

    return $run;
}

function txSavedRunning(array $s, string $mode = 'text'): MedicalAiExtraction
{
    return txSavedRun($s, ['mode' => $mode, 'status' => 'running', 'started_at' => now()]);
}

function txSavedStatus(string $id): string
{
    return (string) DB::table('medical_ai_extractions')->where('id', $id)->value('status');
}

function txSavedPayload(): array
{
    return ['schema_version' => 1, 'document_date' => null, 'specialty_key' => null, 'visits' => [], 'medications' => [], 'allergies' => [], 'vaccinations' => []];
}

function txSavedAttempt(callable $f): bool
{
    try {
        DB::transaction($f);

        return false;
    } catch (QueryException) {
        return true;
    }
}

it('defaults existing rows to mode entries and ties the mode, the status and the payload together', function () {
    $s = txSavedSetup();
    $plain = txSavedRun($s, ['status' => 'failed', 'failure_code' => 'timeout', 'finished_at' => now()]);
    expect(DB::table('medical_ai_extractions')->where('id', $plain->id)->value('mode'))->toBe('entries');

    $base = fn (array $over) => array_merge([
        'id' => (string) Str::uuid(), 'subject_id' => $s['subject']->id, 'document_id' => $s['doc']->id, 'status' => 'failed',
        'failure_code' => 'timeout', 'finished_at' => now(), 'agent' => 'codex', 'schema_version' => 1, 'prompt_version' => 1,
        'started_by' => $s['user']->id, 'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ], $over);

    expect(txSavedAttempt(fn () => DB::table('medical_ai_extractions')->insert($base(['status' => 'text_saved', 'failure_code' => null, 'mode' => 'full']))))->toBeTrue()
        ->and(txSavedAttempt(fn () => DB::table('medical_ai_extractions')->insert($base(['status' => 'ready', 'failure_code' => null, 'mode' => 'text', 'payload' => json_encode(txSavedPayload())]))))->toBeTrue()
        ->and(txSavedAttempt(fn () => DB::table('medical_ai_extractions')->insert($base(['mode' => 'x']))))->toBeTrue()
        ->and(txSavedAttempt(fn () => DB::table('medical_ai_extractions')->insert($base(['status' => 'text_saved', 'failure_code' => null, 'mode' => 'text']))))->toBeFalse();
});

it('refuses to roll the migration back while a text run exists, and rolls back cleanly otherwise', function () {
    $s = txSavedSetup();
    txSavedRun($s, ['status' => 'text_saved', 'mode' => 'text', 'finished_at' => now()]);
    $migration = require database_path('migrations/2026_12_03_100000_add_text_mode_to_medical_ai_extractions.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(DB::table('medical_ai_extractions')->where('status', 'text_saved')->count())->toBe(1);
});

it('writes mode entries when the OCR switch is off and mode full when it is on', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Queue::fake();
    $s = txSavedSetup();
    $url = "/health/people/{$s['subject']->id}/documents/{$s['doc']->id}/ai";

    $id = (string) $this->actingAs($s['user'])->postJson($url, ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202)->json('extractionId');
    expect(DB::table('medical_ai_extractions')->where('id', $id)->value('mode'))->toBe('entries');

    DB::table('medical_ai_extractions')->where('id', $id)->update(['status' => 'cancelled', 'cancel_reason' => 'user', 'finished_at' => now()->subHour()]);
    config(['ai.ocr.enabled' => true, 'ai.limits.document_cooldown_minutes' => 0]);
    $id = (string) $this->actingAs($s['user'])->postJson($url, ['acknowledged' => true, 'providers' => ['codex']])->assertStatus(202)->json('extractionId');
    expect(DB::table('medical_ai_extractions')->where('id', $id)->value('mode'))->toBe('full');
});

it('claims the mode of the run', function () {
    $s = txSavedSetup();
    $run = txSavedRun($s, ['mode' => 'text']);

    expect(app(AiJobQuery::class)->claim($run->id)['mode'])->toBe('text');
});

it('ends a text-only run as text_saved without touching a proposal or its decisions', function () {
    $s = txSavedSetup();
    $old = MedicalAiExtraction::factory()->forDocument($s['doc'])->ready(txSavedPayload(), 0)->create(['started_by' => $s['user']->id]);
    $run = txSavedRunning($s);
    DocumentTextFixtures::save($s['doc'], ['one', 'two'], 2, true);
    DB::table('medical_document_texts')->where('document_id', $s['doc']->id)->update(['extraction_id' => $run->id]);

    $ok = app(AiJobQuery::class)->finishTextSaved($run->id, 2, 2, 'codex', 'm', 15);
    $row = DB::table('medical_ai_extractions')->where('id', $run->id)->first();

    expect($ok)->toBeTrue()
        ->and($row->status)->toBe('text_saved')->and((int) $row->pages_read)->toBe(2)->and((int) $row->pages_total)->toBe(2)
        ->and($row->finished_at)->not->toBeNull()->and($row->payload)->toBeNull()
        ->and(txSavedStatus($old->id))->toBe('ready')
        ->and(DB::table('ai_calls')->where('extraction_id', $run->id)->value('status'))->toBe('succeeded');
});

it('finishes a text-only run as nothing when a guard fails', function (string $case) {
    $s = txSavedSetup();
    $run = txSavedRunning($s, $case === 'full mode' ? 'full' : 'text');
    DocumentTextFixtures::save($s['doc'], ['one'], 1, true);
    if ($case !== 'no text row') {
        DB::table('medical_document_texts')->where('document_id', $s['doc']->id)->update(['extraction_id' => $run->id]);
    }
    match ($case) {
        'cancel' => DB::table('medical_ai_extractions')->where('id', $run->id)->update(['cancel_requested_at' => now()]),
        'consent' => DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['ai_consent' => false]),
        'person archived' => DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['archived_at' => now()]),
        'document archived' => DB::table('medical_documents')->where('id', $s['doc']->id)->update(['archived_at' => now()]),
        default => null,
    };

    $ok = app(AiJobQuery::class)->finishTextSaved($run->id, 1, 1, 'codex', 'm', 15);

    expect($ok)->toBeFalse()->and(txSavedStatus($run->id))->toBe('running');
})->with(['full mode', 'cancel', 'consent', 'person archived', 'document archived', 'no text row']);

it('reads back only the text this very run stored', function () {
    $s = txSavedSetup();
    $run = txSavedRunning($s);
    $other = txSavedRun($s, ['status' => 'failed', 'failure_code' => 'timeout', 'finished_at' => now()->subDay()]);
    DocumentTextFixtures::save($s['doc'], ['a', 'b', 'c'], 9, false, 'pl');

    DB::table('medical_document_texts')->where('document_id', $s['doc']->id)->update(['extraction_id' => $other->id, 'pages_read' => 3]);
    expect(app(AiJobQuery::class)->savedText($run->id))->toBeNull();

    DB::table('medical_document_texts')->where('document_id', $s['doc']->id)->update(['extraction_id' => $run->id]);
    $saved = app(AiJobQuery::class)->savedText($run->id);

    expect($saved)->toBe(['pages' => ['a', 'b', 'c'], 'pagesRead' => 3, 'pagesTotal' => 9, 'complete' => false, 'language' => 'pl']);
});

it('closes the open call as succeeded with closeStep and leaves the extraction running', function () {
    $s = txSavedSetup();
    $run = txSavedRunning($s);

    app(AiJobQuery::class)->closeStep($run->id, 'm', 40);

    expect(DB::table('ai_calls')->where('extraction_id', $run->id)->value('status'))->toBe('succeeded')
        ->and(txSavedStatus($run->id))->toBe('running');
});

it('shows a ready proposal while a newer text_saved run exists, and a text_saved run alone', function () {
    $s = txSavedSetup();
    $viewer = Viewer::for($s['user']);
    $old = MedicalAiExtraction::factory()->forDocument($s['doc'])->ready(txSavedPayload(), 0)->create(['started_by' => $s['user']->id, 'queued_at' => now()->subHour()]);
    txSavedRun($s, ['status' => 'text_saved', 'mode' => 'text', 'finished_at' => now(), 'pages_read' => 2, 'pages_total' => 2, 'queued_at' => now()]);

    $map = app(AiRunQuery::class)->statusMap($viewer, $s['subject']);
    expect($map[$s['doc']->id]['status'])->toBe('ready')->and($map[$s['doc']->id]['readyExtractionId'])->toBe($old->id);

    DB::table('medical_ai_extractions')->where('id', $old->id)->delete();
    $map = app(AiRunQuery::class)->statusMap($viewer, $s['subject']);
    expect($map[$s['doc']->id])->toMatchArray(['status' => 'text_saved', 'mode' => 'text', 'pagesRead' => 2, 'pagesTotal' => 2, 'readyExtractionId' => null]);
});

it('flags a run that saved text, for edit and view members alike', function () {
    $edit = User::factory()->create();
    $view = User::factory()->create();
    MedicalFixtures::grantHealth($edit, AccessLevel::Edit);
    MedicalFixtures::grantHealth($view, AccessLevel::View);
    $subject = MedicalFixtures::subject(['ai_consent' => true], [[$edit, AccessLevel::Edit], [$view, AccessLevel::View]]);
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Scan']);
    $s = ['user' => $edit, 'subject' => $subject, 'doc' => $doc];
    $run = txSavedRunning($s, 'full');
    DocumentTextFixtures::save($doc, ['x'], 4, false);

    $entry = fn (User $u) => app(AiRunQuery::class)->statusMap(Viewer::for($u), $subject)[$doc->id];
    expect($entry($edit)['textSaved'])->toBeFalse()->and($entry($view)['textSaved'])->toBeFalse();

    DB::table('medical_document_texts')->where('document_id', $doc->id)->update(['extraction_id' => $run->id]);
    expect($entry($edit)['textSaved'])->toBeTrue()->and($entry($view)['textSaved'])->toBeTrue()
        ->and($entry($view))->not->toHaveKey('entryCount');
});

it('opens the ready proposal when a newer text-mode run exists, and ignores it for newerWaiting', function () {
    $s = txSavedSetup();
    $old = MedicalAiExtraction::factory()->forDocument($s['doc'])->ready(txSavedPayload(), 0)->create(['started_by' => $s['user']->id, 'queued_at' => now()->subHour()]);
    txSavedRun($s, ['status' => 'text_saved', 'mode' => 'text', 'finished_at' => now(), 'queued_at' => now()]);
    txSavedRun($s, ['status' => 'cancelled', 'cancel_reason' => 'user', 'mode' => 'text', 'finished_at' => now(), 'queued_at' => now()->addMinute()]);

    $props = app(AiReviewQuery::class)->review(Viewer::for($s['user']), $s['subject'], (string) $s['doc']->id, null);

    expect($props['extraction']['id'] ?? null)->toBe($old->id);
});

it('starts the cool-down after a text_saved run', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Queue::fake();
    $s = txSavedSetup();
    txSavedRun($s, ['status' => 'text_saved', 'mode' => 'text', 'finished_at' => now()->subMinutes(5)]);
    config(['ai.limits.document_cooldown_minutes' => 60]);

    $this->actingAs($s['user'])->postJson("/health/people/{$s['subject']->id}/documents/{$s['doc']->id}/ai", ['acknowledged' => true, 'providers' => ['codex']])
        ->assertStatus(409);

    $candidate = app(AiBulkEligibility::class)->nextCandidate('all', (string) Str::uuid());
    expect($candidate)->toBeNull();
});

it('keeps last_failed from being hidden or created by a text-only run', function () {
    $s = txSavedSetup();
    txSavedRun($s, ['status' => 'failed', 'failure_code' => 'worker_lost', 'finished_at' => now()->subDay(), 'queued_at' => now()->subDay()]);
    txSavedRun($s, ['status' => 'text_saved', 'mode' => 'text', 'finished_at' => now()->subDay(), 'queued_at' => now()->subHours(2)]);
    config(['ai.limits.document_cooldown_minutes' => 0]);

    expect(app(AiBulkEligibility::class)->nextCandidate('last_failed', (string) Str::uuid()))->not->toBeNull();

    DB::table('medical_ai_extractions')->where('mode', 'entries')->delete();
    txSavedRun($s, ['status' => 'failed', 'failure_code' => 'worker_lost', 'mode' => 'text', 'finished_at' => now()->subDay(), 'queued_at' => now()]);

    expect(app(AiBulkEligibility::class)->nextCandidate('last_failed', (string) Str::uuid()))->toBeNull();
});
