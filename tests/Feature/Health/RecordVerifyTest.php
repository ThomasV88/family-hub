<?php

use App\Access\AccessLevel;
use App\Models\AuditEntry;
use App\Models\MedicalAllergy;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\MedicalVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @return array<string, array{0: class-string, 1: string, 2: string, 3: string}> */
function verifyTypes(): array
{
    return [
        'visit' => [MedicalVisit::class, 'visits', 'id', 'health.visits.verify'],
        'medication' => [MedicalMedication::class, 'medications', 'id', 'health.medications.verify'],
        'allergy' => [MedicalAllergy::class, 'allergies', 'id', 'health.allergies.verify'],
        'vaccination' => [MedicalVaccination::class, 'vaccinations', 'public_id', 'health.vaccinations.verify'],
    ];
}

function verifySetup(AccessLevel $level = AccessLevel::Edit): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, $level]])];
}

function verifyUrl(string $route, MedicalSubject $subject, string $id): string
{
    $param = ['health.visits.verify' => 'visit', 'health.medications.verify' => 'medication', 'health.allergies.verify' => 'allergy', 'health.vaccinations.verify' => 'vaccination'][$route];

    return route($route, ['person' => $subject->getKey(), $param => $id], false);
}

it('lets an edit member verify an AI record and audits it', function (string $model, string $plural, string $key, string $route) {
    [$user, $subject] = verifySetup();
    $record = $model::factory()->create(['subject_id' => $subject->getKey(), 'source' => 'ai']);
    $before = AuditEntry::where('event', 'health_write')->count();

    $this->actingAs($user)->post(verifyUrl($route, $subject, (string) $record->{$key}))
        ->assertRedirect(route('health.people.show', ['person' => $subject->getKey()]))
        ->assertSessionHas('toast', ['key' => 'health.ai.provenance.verified_toast']);

    $fresh = $record->fresh();
    expect($fresh->verified_at)->not->toBeNull()->and((int) $fresh->verified_by)->toBe($user->id);
    expect(AuditEntry::where('event', 'health_write')->count())->toBe($before + 1);
})->with(array_map(fn ($t) => $t, verifyTypes()));

it('leaves manual and already verified records unchanged without an error', function (string $model, string $plural, string $key, string $route) {
    [$user, $subject] = verifySetup();
    $manual = $model::factory()->create(['subject_id' => $subject->getKey(), 'source' => 'manual']);
    $done = $model::factory()->create(['subject_id' => $subject->getKey(), 'source' => 'ai']);
    $this->actingAs($user)->post(verifyUrl($route, $subject, (string) $done->{$key}))->assertRedirect();
    $stamp = $done->fresh()->verified_at;

    $this->actingAs($user)->post(verifyUrl($route, $subject, (string) $manual->{$key}))->assertRedirect();
    $this->actingAs($user)->post(verifyUrl($route, $subject, (string) $done->{$key}))->assertRedirect();

    expect($manual->fresh()->verified_at)->toBeNull();
    expect($done->fresh()->verified_at->toIso8601String())->toBe($stamp->toIso8601String());
})->with(verifyTypes());

it('answers every other caller with the same 403', function (string $model, string $plural, string $key, string $route) {
    [$user, $subject] = verifySetup();
    $record = $model::factory()->create(['subject_id' => $subject->getKey(), 'source' => 'ai']);
    $id = (string) $record->{$key};

    $viewOnly = User::factory()->create();
    MedicalFixtures::grantHealth($viewOnly);
    $viewSubject = MedicalFixtures::subject([], [[$viewOnly, AccessLevel::View]]);
    $viewRecord = $model::factory()->create(['subject_id' => $viewSubject->getKey(), 'source' => 'ai']);

    $stranger = User::factory()->create();
    MedicalFixtures::grantHealth($stranger);
    $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
    $hiddenRecord = $model::factory()->create(['subject_id' => $hidden->getKey(), 'source' => 'ai']);

    $this->actingAs($viewOnly)->post(verifyUrl($route, $viewSubject, (string) $viewRecord->{$key}))->assertForbidden();
    $this->actingAs($stranger)->post(verifyUrl($route, $subject, $id))->assertForbidden();
    $this->actingAs($stranger)->post(verifyUrl($route, $hidden, (string) $hiddenRecord->{$key}))->assertForbidden();
    // A hidden record id under the caller's own person, a random uuid and a malformed id.
    $this->actingAs($user)->post(verifyUrl($route, $subject, (string) $hiddenRecord->{$key}))->assertForbidden();
    $this->actingAs($user)->post(verifyUrl($route, $subject, (string) Str::uuid7()))->assertForbidden();
    $this->actingAs($user)->post(verifyUrl($route, $subject, 'abc'))->assertForbidden();

    expect($viewRecord->fresh()->verified_at)->toBeNull()->and($hiddenRecord->fresh()->verified_at)->toBeNull()->and($record->fresh()->verified_at)->toBeNull();
})->with(verifyTypes());

it('exposes provenance on every record row of the person page', function () {
    [$user, $subject] = verifySetup();
    $id = $subject->getKey();
    MedicalVisit::factory()->create(['subject_id' => $id, 'source' => 'manual']);
    MedicalMedication::factory()->create(['subject_id' => $id, 'source' => 'ai']);
    MedicalAllergy::factory()->create(['subject_id' => $id, 'source' => 'ai', 'verified_at' => now(), 'verified_by' => $user->id]);
    MedicalVaccination::factory()->create(['subject_id' => $id, 'source' => 'ai']);

    $this->actingAs($user)->get(route('health.people.show', ['person' => $id], false))->assertInertia(fn (Assert $page) => $page
        ->where('visits.0.provenance', ['source' => 'manual', 'verification' => 'manual'])
        ->where('medications.0.provenance', ['source' => 'ai', 'verification' => 'unverified'])
        ->where('allergies.0.provenance', ['source' => 'ai', 'verification' => 'verified'])
        ->where('vaccinations.0.provenance', ['source' => 'ai', 'verification' => 'unverified']));
});
