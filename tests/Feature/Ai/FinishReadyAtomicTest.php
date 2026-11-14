<?php

use App\Access\AccessLevel;
use App\Medical\Queries\AiJobQuery;
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

    $ok = app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'default', 10);

    expect($ok)->toBeTrue()->and(frStatus($s['run']->id))->toBe('ready')->and(frStatus($s['old']->id))->toBe('superseded');
});

it('supersedes nothing when the run was cancelled, consent was withdrawn or the row is no longer running', function (string $case) {
    $s = frSetup();
    match ($case) {
        'cancel' => DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['cancel_requested_at' => now()]),
        'consent' => DB::table('medical_subjects')->where('id', $s['subject']->id)->update(['ai_consent' => false]),
        'closed' => DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['status' => 'failed', 'failure_code' => 'worker_lost', 'finished_at' => now()]),
    };

    $ok = app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'default', 10);

    expect($ok)->toBeFalse()->and(frStatus($s['old']->id))->toBe('ready')
        ->and(frStatus($s['run']->id))->not->toBe('ready')
        ->and(DB::table('medical_ai_extractions')->where('id', $s['run']->id)->value('payload'))->toBeNull();
})->with(['cancel', 'consent', 'closed']);

it('keeps the proposal when the call-log write is refused by its guard', function () {
    $s = frSetup();
    DB::table('ai_calls')->where('extraction_id', $s['run']->id)->update(['model' => 'early']);

    $ok = app(AiJobQuery::class)->finishReady($s['run']->id, frPayload(), 1, 1, 1, 'gpt-other', 10);

    expect($ok)->toBeTrue()->and(frStatus($s['run']->id))->toBe('ready')->and(frStatus($s['old']->id))->toBe('superseded');
});
