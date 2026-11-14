<?php

use App\Access\AccessLevel;
use App\Medical\Queries\AiJobQuery;
use App\Models\AiBulkRun;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\MedicalFixtures;

function frPayload(): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [], 'medications' => [['name' => 'Enalapril', 'dose' => null, 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => [],
    ];
}

/** A person with consent, one document with an older ready proposal and a running re-run. */
function frSetup(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $subject = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Scan']);
    $old = MedicalAiExtraction::factory()->forDocument($doc)->ready(frPayload(), 1)->create(['started_by' => $user->id]);
    $run = MedicalAiExtraction::factory()->forDocument($doc)->create(['started_by' => $user->id, 'status' => 'running', 'started_at' => now()]);
    AiCall::factory()->create(['extraction_id' => $run->id, 'subject_id' => $subject->id, 'document_id' => $doc->id, 'started_by' => $user->id, 'status' => 'running']);

    return compact('user', 'subject', 'doc', 'old', 'run');
}

function frStatus(string $id): string
{
    return (string) DB::table('medical_ai_extractions')->where('id', $id)->value('status');
}

it('writes the new proposal and supersedes the old one when the run is still live', function () {
    $s = frSetup();

    $ok = app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'codex', 'default', 10);

    expect($ok)->toBeTrue()->and(frStatus($s['run']->id))->toBe('ready')->and(frStatus($s['old']->id))->toBe('superseded');
});

it('supersedes nothing when the run was cancelled, consent was withdrawn or the row is no longer running', function (string $case) {
    $s = frSetup();
    match ($case) {
        'cancel' => DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['cancel_requested_at' => now()]),
        'consent' => DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['ai_consent' => false]),
        'closed' => DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['status' => 'failed', 'failure_code' => 'worker_lost', 'finished_at' => now()]),
    };

    $ok = app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'codex', 'default', 10);

    expect($ok)->toBeFalse()->and(frStatus($s['old']->id))->toBe('ready')
        ->and(frStatus($s['run']->id))->not->toBe('ready')
        ->and(DB::table('medical_ai_extractions')->where('id', $s['run']->id)->value('payload'))->toBeNull();
})->with(['cancel', 'consent', 'closed']);

it('keeps the proposal when the call-log write is refused by its guard', function () {
    $s = frSetup();
    DB::table('ai_calls')->where('extraction_id', $s['run']->id)->update(['model' => 'early']);

    $ok = app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'codex', 'gpt-other', 10);

    expect($ok)->toBeTrue()->and(frStatus($s['run']->id))->toBe('ready')->and(frStatus($s['old']->id))->toBe('superseded');
});

it('records the agent that produced the proposal', function () {
    $s = frSetup();

    app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'copilot', 'default', 10);

    expect(DB::table('medical_ai_extractions')->where('id', $s['run']->id)->value('agent'))->toBe('copilot');
});

function frAccept(array $s): void
{
    DB::table('medical_ai_decisions')->insert([
        'extraction_id' => $s['old']->id, 'entry_key' => 'medication-0', 'decision' => 'accepted',
        'record_type' => 'medication', 'record_id' => '1', 'decided_by' => $s['user']->id,
    ]);
}

it('never supersedes a proposal with accepted entries for a bulk row, but does for rows without one', function () {
    $s = frSetup();
    $bulk = AiBulkRun::factory()->create(['started_by' => $s['user']->id]);
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['bulk_run_id' => $bulk->id]);
    frAccept($s);

    $ok = app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'codex', 'default', 10);

    $row = DB::table('medical_ai_extractions')->where('id', $s['run']->id)->first();
    expect($ok)->toBeFalse()->and($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('decided')
        ->and($row->payload)->toBeNull()->and(frStatus($s['old']->id))->toBe('ready')
        ->and(DB::table('ai_calls')->where('extraction_id', $s['run']->id)->value('status'))->toBe('cancelled')
        ->and(app(AiJobQuery::class)->finishedAsDecided($s['run']->id))->toBeTrue();
});

it('lets a bulk row supersede a proposal nobody accepted anything from, and an interactive row always', function () {
    $s = frSetup();
    $bulk = AiBulkRun::factory()->create(['started_by' => $s['user']->id]);
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['bulk_run_id' => $bulk->id]);

    expect(app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'codex', 'default', 10))->toBeTrue()
        ->and(frStatus($s['old']->id))->toBe('superseded');

    $i = frSetup();
    frAccept($i);
    expect(app(AiJobQuery::class)->finishReady($i['run']->id, frPayload(), 1, 1, 1, 'codex', 'default', 10))->toBeTrue()
        ->and(frStatus($i['old']->id))->toBe('superseded');
});
