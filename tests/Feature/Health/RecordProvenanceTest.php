<?php

use App\Access\AccessLevel;
use App\Http\Requests\Health\AllergyRequest;
use App\Http\Requests\Health\MedicationRequest;
use App\Http\Requests\Health\VaccinationRequest;
use App\Http\Requests\Health\VisitRequest;
use App\Medical\Provenance;
use App\Medical\Queries\AllergyQuery;
use App\Medical\Queries\MedicationQuery;
use App\Medical\Queries\VaccinationQuery;
use App\Medical\Queries\VisitQuery;
use App\Medical\Viewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @return array<string, array{0: class-string, 1: array<string, mixed>, 2: string, 3: class-string}> */
function provenanceTypes(): array
{
    return [
        'visit' => [VisitQuery::class, ['visited_on' => '2026-01-10', 'specialty_key' => 'cardiology'], 'id', VisitRequest::class],
        'medication' => [MedicationQuery::class, ['name' => 'Ibuprofen'], 'id', MedicationRequest::class],
        'allergy' => [AllergyQuery::class, ['substance' => 'Pollen', 'severity' => 'mild'], 'id', AllergyRequest::class],
        'vaccination' => [VaccinationQuery::class, ['vaccine' => 'MMR', 'given_on' => '2026-01-10'], 'public_id', VaccinationRequest::class],
    ];
}

function provenanceSetup(AccessLevel $level = AccessLevel::Edit): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $subject = MedicalFixtures::subject([], [[$user, $level]]);

    return [$user, $subject, Viewer::for($user)];
}

describe('create with source', function () {
    it('stores ai provenance only through the argument', function (string $query, array $data, string $key) {
        [, $subject, $viewer] = provenanceSetup();

        $ai = app($query)->create($viewer, $subject, $data, 'ai');
        expect($ai->source)->toBe('ai')->and($ai->verified_at)->toBeNull();
        expect(Provenance::present($ai->fresh())['verification'])->toBe('unverified');

        $manual = app($query)->create($viewer, $subject, $data + ['source' => 'ai', 'verified_at' => now()->toDateTimeString(), 'verified_by' => 1]);
        expect($manual->source)->toBe('manual')->and($manual->fresh()->verified_at)->toBeNull();
        expect($manual->fresh()->verified_by)->toBeNull();
    })->with(fn () => collect(provenanceTypes())->map(fn ($t) => array_slice($t, 0, 3))->all());

    it('refuses an unknown source', function (string $query, array $data) {
        [, $subject, $viewer] = provenanceSetup();
        expect(fn () => app($query)->create($viewer, $subject, $data, 'robot'))->toThrow(InvalidArgumentException::class);
    })->with(fn () => collect(provenanceTypes())->map(fn ($t) => array_slice($t, 0, 2))->all());
});

describe('verify', function () {
    it('lets an edit member verify an unverified ai record once', function (string $query, array $data) {
        [$user, $subject, $viewer] = provenanceSetup();
        $row = app($query)->create($viewer, $subject, $data, 'ai');
        $id = $query === VaccinationQuery::class ? $row->public_id : $row->id;

        expect(app($query)->verify($viewer, $subject, $id))->toBeTrue();
        $fresh = app($query)->find($viewer, $subject, $id);
        expect($fresh->verified_at)->not->toBeNull()->and((int) $fresh->verified_by)->toBe($user->id);
        expect(Provenance::present($fresh)['verification'])->toBe('verified');

        $at = $fresh->verified_at;
        expect(app($query)->verify($viewer, $subject, $id))->toBeFalse();
        expect(app($query)->find($viewer, $subject, $id)->verified_at->equalTo($at))->toBeTrue();
    })->with(fn () => collect(provenanceTypes())->map(fn ($t) => array_slice($t, 0, 2))->all());

    it('does nothing for manual records, view members, hidden people and unknown ids', function (string $query, array $data) {
        $editor = User::factory()->create();
        $viewOnly = User::factory()->create();
        MedicalFixtures::grantHealth($editor);
        MedicalFixtures::grantHealth($viewOnly, AccessLevel::View);
        $subject = MedicalFixtures::subject([], [[$editor, AccessLevel::Edit], [$viewOnly, AccessLevel::View]]);
        $viewer = Viewer::for($editor);
        $manual = app($query)->create($viewer, $subject, $data);
        $ai = app($query)->create($viewer, $subject, $data, 'ai');
        $idOf = fn ($r) => $query === VaccinationQuery::class ? $r->public_id : $r->id;

        expect(app($query)->verify($viewer, $subject, $idOf($manual)))->toBeFalse();
        expect($manual->fresh()->verified_at)->toBeNull();

        expect(app($query)->verify(Viewer::for($viewOnly), $subject, $idOf($ai)))->toBeFalse();

        $stranger = User::factory()->create();
        MedicalFixtures::grantHealth($stranger);
        expect(app($query)->verify(Viewer::for($stranger), $subject, $idOf($ai)))->toBeFalse();

        expect(app($query)->verify($viewer, $subject, (string) Str::uuid()))->toBeFalse();
        expect(app($query)->verify($viewer, $subject, 'not-a-uuid'))->toBeFalse();
        expect($ai->fresh()->verified_at)->toBeNull();
    })->with(fn () => collect(provenanceTypes())->map(fn ($t) => array_slice($t, 0, 2))->all());
});

describe('shared rules', function () {
    it('exposes the request rule set and messages', function (string $query, array $data, string $key, string $request) {
        expect($request::fieldRules())->toEqual((new $request)->rules());
        expect($request::fieldMessages())->toEqual((new $request)->messages());
    })->with(fn () => provenanceTypes());
});
