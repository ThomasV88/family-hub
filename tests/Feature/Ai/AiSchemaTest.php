<?php

use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function aiDoc(?MedicalSubject $subject = null): MedicalDocument
{
    $subject ??= MedicalSubject::factory()->create();

    return MedicalDocument::factory()->forSubject($subject)->create();
}

function aiInsert(MedicalDocument $doc, array $attrs = []): MedicalAiExtraction
{
    return MedicalAiExtraction::factory()->forDocument($doc)->create($attrs);
}

/** Runs $fn in a savepoint so a constraint failure does not abort the test transaction. */
function aiRefused(callable $fn): bool
{
    try {
        DB::transaction($fn);
    } catch (QueryException) {
        return true;
    }

    return false;
}

it('allows one queued or running run per document', function () {
    $doc = aiDoc();
    aiInsert($doc, ['status' => 'queued']);

    expect(aiRefused(fn () => aiInsert($doc, ['status' => 'queued'])))->toBeTrue();
    expect(aiRefused(fn () => MedicalAiExtraction::factory()->forDocument($doc)->running()->create()))->toBeTrue();
});

it('allows one ready run per document and any number of terminal others', function () {
    $doc = aiDoc();
    MedicalAiExtraction::factory()->forDocument($doc)->ready()->create();

    expect(aiRefused(fn () => MedicalAiExtraction::factory()->forDocument($doc)->ready()->create()))->toBeTrue();
    MedicalAiExtraction::factory()->forDocument($doc)->failed()->count(2)->create();
    MedicalAiExtraction::factory()->forDocument($doc)->cancelled()->count(2)->create();
    MedicalAiExtraction::factory()->forDocument($doc)->superseded()->count(2)->create();
    MedicalAiExtraction::factory()->forDocument($doc)->running()->create();

    expect(MedicalAiExtraction::count())->toBe(8);
});

it('keeps payload, cancel reason and failure code in step with the status', function () {
    $doc = aiDoc();

    expect(aiRefused(fn () => aiInsert($doc, ['status' => 'queued', 'payload' => [1]])))->toBeTrue()
        ->and(aiRefused(fn () => aiInsert($doc, ['status' => 'ready', 'payload' => null])))->toBeTrue()
        ->and(aiRefused(fn () => aiInsert($doc, ['status' => 'cancelled', 'cancel_reason' => null])))->toBeTrue()
        ->and(aiRefused(fn () => aiInsert($doc, ['status' => 'failed', 'failure_code' => null])))->toBeTrue()
        ->and(aiRefused(fn () => aiInsert($doc, ['status' => 'queued', 'failure_code' => 'x'])))->toBeTrue()
        ->and(aiRefused(fn () => aiInsert($doc, ['status' => 'bogus'])))->toBeTrue()
        ->and(aiRefused(fn () => MedicalAiExtraction::factory()->forDocument($doc)->cancelled('nope')->create()))->toBeTrue();
});

it('refuses a proposal whose document belongs to another person', function () {
    $doc = aiDoc();
    $other = MedicalSubject::factory()->create();

    expect(aiRefused(fn () => aiInsert($doc, ['subject_id' => $other->id])))->toBeTrue();
});

it('cascades with the document and with the person, decisions included', function () {
    $doc = aiDoc();
    $run = MedicalAiExtraction::factory()->forDocument($doc)->ready()->create();
    DB::table('medical_ai_decisions')->insert(['extraction_id' => $run->id, 'entry_key' => 'e1', 'decision' => 'rejected', 'decided_by' => 1]);

    DB::table('medical_documents')->where('id', $doc->id)->delete();
    expect(DB::table('medical_ai_extractions')->count())->toBe(0)
        ->and(DB::table('medical_ai_decisions')->count())->toBe(0);

    $doc2 = aiDoc();
    $run2 = MedicalAiExtraction::factory()->forDocument($doc2)->ready()->create();
    DB::table('medical_ai_decisions')->insert(['extraction_id' => $run2->id, 'entry_key' => 'e1', 'decision' => 'rejected', 'decided_by' => 1]);
    DB::table('medical_subjects')->where('id', $doc2->subject_id)->delete();

    expect(DB::table('medical_ai_extractions')->count())->toBe(0)
        ->and(DB::table('medical_ai_decisions')->count())->toBe(0);
});

it('enforces the decision invariants', function () {
    $run = MedicalAiExtraction::factory()->forDocument(aiDoc())->ready()->create();
    $base = ['extraction_id' => $run->id, 'decided_by' => 1];

    expect(aiRefused(fn () => DB::table('medical_ai_decisions')->insert($base + ['entry_key' => 'a', 'decision' => 'accepted'])))->toBeTrue()
        ->and(aiRefused(fn () => DB::table('medical_ai_decisions')->insert($base + ['entry_key' => 'a', 'decision' => 'rejected', 'record_id' => 'x'])))->toBeTrue()
        ->and(aiRefused(fn () => DB::table('medical_ai_decisions')->insert($base + ['entry_key' => 'a', 'decision' => 'accepted', 'record_id' => 'x', 'record_type' => 'bogus'])))->toBeTrue();

    DB::table('medical_ai_decisions')->insert($base + ['entry_key' => 'a', 'decision' => 'accepted', 'record_id' => 'r1', 'record_type' => 'visit']);

    expect(aiRefused(fn () => DB::table('medical_ai_decisions')->insert($base + ['entry_key' => 'a', 'decision' => 'rejected'])))->toBeTrue();
});

it('casts the payload and exposes the factory states', function () {
    $doc = aiDoc();
    $run = MedicalAiExtraction::factory()->forDocument($doc)->ready(['entries' => [['k' => 1]]], 1)->create();

    expect($run->fresh()->payload)->toBe(['entries' => [['k' => 1]]])
        ->and($run->fresh()->entry_count)->toBe(1)
        ->and($run->getKey())->toBeString();
});

it('keeps the new tables inside the leak net', function () {
    $src = file_get_contents(base_path('tests/Support/MedicalPrivacy.php'));
    expect(substr_count($src, "'medical_ai_extractions'"))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($src, "'medical_ai_decisions'"))->toBeGreaterThanOrEqual(2);
});
