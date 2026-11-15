<?php

use App\Medical\AiConsent;
use App\Medical\Queries\SystemQuery;
use App\Models\MedicalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

it('allows a document only when its person has consent on', function () {
    $on = MedicalFixtures::subject(['ai_consent' => true]);
    $off = MedicalFixtures::subject(['ai_consent' => false]);
    $onDoc = MedicalDocument::factory()->forSubject($on)->create();
    $offDoc = MedicalDocument::factory()->forSubject($off)->create();

    expect(AiConsent::allowsDocument($onDoc->id))->toBeTrue()
        ->and(AiConsent::allowsDocument($offDoc->id))->toBeFalse();

    // Read on every call: a change applies immediately.
    DB::table('medical_subjects')->where('id', $on->id)->update(['ai_consent' => false]);
    expect(AiConsent::allowsDocument($onDoc->id))->toBeFalse();
});

it('is false for an unknown id and a malformed id, the latter without SQL', function () {
    expect(AiConsent::allowsDocument((string) Str::uuid()))->toBeFalse();

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(AiConsent::allowsDocument('abc'))->toBeFalse()->and(AiConsent::allowsDocument(''))->toBeFalse();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toBe([]);
});

it('is false on any error', function () {
    $this->app->bind(SystemQuery::class, fn () => throw new RuntimeException('down'));

    expect(AiConsent::allowsDocument((string) Str::uuid()))->toBeFalse();
});

it('returns only the subject id string from documentSubjectId', function () {
    $subject = MedicalFixtures::subject();
    $document = MedicalDocument::factory()->forSubject($subject)->create(['title' => 'ZxCanaryMed']);
    $system = new SystemQuery;

    expect($system->documentSubjectId($document->id))->toBe($subject->id)
        ->and($system->documentSubjectId((string) Str::uuid()))->toBeNull()
        ->and($system->documentSubjectId('abc'))->toBeNull();
});

it('answers knownPaths and existingSubjectIds with paths and ids only', function () {
    $subject = MedicalFixtures::subject();
    $image = MedicalDocument::factory()->image()->forSubject($subject)->create();
    $system = new SystemQuery;
    $stray = $subject->id.'/'.strtoupper((string) Str::ulid()).'.pdf';

    expect($system->knownPaths([$image->path, $image->thumb_path, $stray]))->toEqualCanonicalizing([$image->path, $image->thumb_path])
        ->and($system->knownPaths([]))->toBe([])
        ->and($system->existingSubjectIds([$subject->id, (string) Str::uuid(), 'abc']))->toBe([$subject->id])
        ->and($system->existingSubjectIds(['abc']))->toBe([]);
});
