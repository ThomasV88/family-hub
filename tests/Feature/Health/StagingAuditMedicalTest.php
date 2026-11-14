<?php

use App\Access\AccessLevel;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Tests\Support\MedicalFixtures;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

it('passes with medical subjects and memberships of synthetic users only', function () {
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    $linked = User::factory()->create(['email' => 'a.2@example.test']);
    MedicalFixtures::subject(['created_by' => $synthetic->id, 'user_id' => $linked->id], [[$synthetic, AccessLevel::Edit]]);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('fails on a medical subject created by, linked to or shared with a real user', function () {
    User::factory()->create(['email' => 'a.1@example.test']);
    $real = User::factory()->create(['email' => 'user@real.sk']);
    MedicalFixtures::subject(['created_by' => $real->id], [[$real, AccessLevel::View]]);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic medical_subjects')
        ->expectsOutputToContain('non-synthetic medical_subject_members')
        ->assertExitCode(1);
});

it('fails on a subject linked to a real user even when a synthetic user created it', function () {
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    $real = User::factory()->create(['email' => 'user@real.sk']);
    MedicalSubject::factory()->create(['created_by' => $synthetic->id, 'user_id' => $real->id]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic medical_subjects')->assertExitCode(1);
});

it('fails on a medical subject whose creator was deleted (null created_by)', function () {
    User::factory()->create(['email' => 'a.1@example.test']);
    MedicalSubject::factory()->create(['created_by' => null]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic medical_subjects')->assertExitCode(1);
});

/** A synthetic subject with one document, so an extraction row has real parents. */
function stagingAiDocument(User $owner): MedicalDocument
{
    $subject = MedicalFixtures::subject(['created_by' => $owner->id], [[$owner, AccessLevel::Edit]]);

    return MedicalDocument::factory()->pdf()->create(['subject_id' => $subject->getKey()]);
}

it('passes with AI extractions and AI calls started by synthetic users only', function () {
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    $document = stagingAiDocument($synthetic);
    MedicalAiExtraction::factory()->forDocument($document)->queued()->create(['started_by' => $synthetic->id]);
    AiCall::factory()->create(['started_by' => $synthetic->id, 'subject_id' => $document->subject_id, 'document_id' => $document->getKey()]);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('fails on an AI extraction or AI call started by a real user', function () {
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    $real = User::factory()->create(['email' => 'user@real.sk']);
    $document = stagingAiDocument($synthetic);
    MedicalAiExtraction::factory()->forDocument($document)->queued()->create(['started_by' => $real->id]);
    AiCall::factory()->create(['started_by' => $real->id, 'subject_id' => $document->subject_id, 'document_id' => $document->getKey()]);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic medical_ai_extractions')
        ->expectsOutputToContain('non-synthetic ai_calls')
        ->assertExitCode(1);
});

it('fails on an AI call whose starter no longer exists', function () {
    User::factory()->create(['email' => 'a.1@example.test']);
    AiCall::factory()->create(['started_by' => 999999]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic ai_calls')->assertExitCode(1);
});
