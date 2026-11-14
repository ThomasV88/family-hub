<?php

use App\Access\AccessLevel;
use App\Audit\AuditLogger;
use App\Medical\Queries\AiReviewQuery;
use App\Medical\Viewer;
use App\Models\AuditEntry;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function acceptPayload(): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [
            ['visited_on' => '2026-01-05', 'doctor' => 'Dr. A', 'specialty_key' => 'cardiology', 'specialty_other' => null, 'diagnosis' => null, 'notes' => null],
            ['visited_on' => '2026-01-06', 'doctor' => null, 'specialty_key' => 'general_practice', 'specialty_other' => null, 'diagnosis' => null, 'notes' => null],
            ['visited_on' => '2026-01-07', 'doctor' => str_repeat('x', 121), 'specialty_key' => 'general_practice', 'specialty_other' => null, 'diagnosis' => null, 'notes' => null],
        ],
        'medications' => [['name' => 'Ibuprofen', 'dose' => '200 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [
            ['substance' => 'Pollen', 'reaction' => null, 'severity' => null],
            ['substance' => 'Dust', 'reaction' => 'sneezing', 'severity' => 'unknown'],
        ],
        'vaccinations' => [['vaccine' => 'MMR', 'given_on' => null, 'next_due_on' => null, 'notes' => null]],
    ];
}

/** @return array{0: User, 1: MedicalSubject, 2: MedicalDocument, 3: MedicalAiExtraction} */
function acceptSetup(AccessLevel $level = AccessLevel::Edit, string $state = 'ready', bool $consent = true): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject(['ai_consent' => $consent], [[$user, $level]]);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
    $factory = MedicalAiExtraction::factory()->forDocument($document);
    $extraction = ($state === 'ready' ? $factory->ready(acceptPayload(), 7) : ($state === 'superseded' ? $factory->superseded(acceptPayload()) : $factory->{$state}()))
        ->create(['started_by' => $user->id]);

    return [$user, $person, $document, $extraction];
}

function acceptUrl(MedicalSubject $person, string $extraction, string $entry, string $action = 'accept'): string
{
    return "/health/people/{$person->id}/ai/{$extraction}/entries/{$entry}/{$action}";
}

/** Status and message only: the debug body of a 403 carries a stack trace with test line numbers. */
function acceptShape(TestResponse $response): string
{
    return $response->getStatusCode().'|'.(string) $response->json('message');
}

/** @return array<string, int> */
function acceptCounts(): array
{
    return [
        'visits' => DB::table('medical_visits')->count(),
        'medications' => DB::table('medical_medications')->count(),
        'allergies' => DB::table('medical_allergies')->count(),
        'vaccinations' => DB::table('medical_vaccinations')->count(),
        'decisions' => DB::table('medical_ai_decisions')->count(),
        'audit' => AuditEntry::where('event', 'medical_ai_entry_decided')->count(),
    ];
}

describe('accept', function () {
    it('creates one AI-sourced unverified record, a decision, a decided audit row and the write audit row', function () {
        [$user, $person, $document, $extraction] = acceptSetup();
        $writes = AuditEntry::where('event', 'health_write')->count();

        $response = $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0'), [])->assertOk();

        $medication = DB::table('medical_medications')->sole();
        expect($medication->source)->toBe('ai')->and($medication->verified_at)->toBeNull()->and($medication->name)->toBe('Ibuprofen')
            ->and($medication->subject_id)->toBe($person->id);
        $response->assertExactJson(['state' => 'accepted', 'recordType' => 'medication', 'recordId' => $medication->id, 'edited' => false, 'pending' => 6]);
        $decision = DB::table('medical_ai_decisions')->sole();
        expect($decision->decision)->toBe('accepted')->and($decision->record_type)->toBe('medication')->and($decision->record_id)->toBe($medication->id)
            ->and((int) $decision->decided_by)->toBe($user->id)->and((bool) $decision->edited)->toBeFalse();
        expect(AuditEntry::where('event', 'medical_ai_entry_decided')->sole()->meta)->toEqual(['subject' => $person->id, 'document' => $document->id, 'decision' => 'accepted']);
        expect(AuditEntry::where('event', 'health_write')->count())->toBe($writes + 1);
    });

    it('stays one record for a repeat and for two tabs, answering the existing state', function () {
        [$user, $person, , $extraction] = acceptSetup();
        $url = acceptUrl($person, $extraction->id, 'medication-0');

        $first = $this->actingAs($user)->postJson($url, [])->assertOk()->json();
        $second = $this->actingAs($user)->postJson($url, [])->assertOk()->json();
        $third = $this->actingAs($user)->postJson($url, ['values' => ['name' => 'Different']])->assertOk()->json();

        expect($second)->toBe($first)->and($third)->toBe($first)
            ->and(DB::table('medical_medications')->count())->toBe(1)
            ->and(DB::table('medical_medications')->value('name'))->toBe('Ibuprofen')
            ->and(DB::table('medical_ai_decisions')->count())->toBe(1)
            ->and(AuditEntry::where('event', 'medical_ai_entry_decided')->count())->toBe(1);
    });

    it('links a document without a visit to the new visit and leaves an existing link alone', function () {
        [$user, $person, $document, $extraction] = acceptSetup();

        $visitId = $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit-0'), [])->assertOk()->json('recordId');
        expect(DB::table('medical_documents')->where('id', $document->id)->value('visit_id'))->toBe($visitId);

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit-1'), [])->assertOk();
        expect(DB::table('medical_documents')->where('id', $document->id)->value('visit_id'))->toBe($visitId)
            ->and(DB::table('medical_visits')->where('source', 'ai')->count())->toBe(2);
    });

    it('answers 422 with a severity error and no decision for an allergy without a real severity, then accepts an edit', function (string $entry) {
        [$user, $person, , $extraction] = acceptSetup();
        $before = acceptCounts();

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, $entry), [])
            ->assertStatus(422)->assertJsonValidationErrors(['severity']);
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, $entry), ['values' => ['severity' => 'unknown']])
            ->assertStatus(422)->assertJsonValidationErrors(['severity']);
        expect(acceptCounts())->toBe($before);

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, $entry), ['values' => ['severity' => 'mild']])
            ->assertOk()->assertJson(['state' => 'accepted', 'recordType' => 'allergy', 'edited' => true]);
        $allergy = DB::table('medical_allergies')->sole();
        expect($allergy->severity)->toBe('mild')->and($allergy->source)->toBe('ai')->and($allergy->verified_at)->toBeNull();
    })->with(['allergy-0', 'allergy-1']);

    it('rejects a vaccination without a date and a visit with an oversized doctor, leaving nothing', function () {
        [$user, $person, , $extraction] = acceptSetup();
        $before = acceptCounts();

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'vaccination-0'), [])->assertStatus(422)->assertJsonValidationErrors(['given_on']);
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit-2'), [])->assertStatus(422)->assertJsonValidationErrors(['doctor']);

        expect(acceptCounts())->toBe($before);
    });

    it('accepts an edited vaccination and marks it edited', function () {
        [$user, $person, , $extraction] = acceptSetup();

        $recordId = $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'vaccination-0'), ['values' => ['given_on' => '2025-05-01', 'next_due_on' => '']])
            ->assertOk()->assertJson(['recordType' => 'vaccination', 'edited' => true])->json('recordId');

        $row = DB::table('medical_vaccinations')->sole();
        expect($row->public_id)->toBe($recordId)->and($row->source)->toBe('ai')->and($row->next_due_on)->toBeNull();
    });

    it('ignores source, verification and subject keys sent as values', function () {
        [$user, $person, , $extraction] = acceptSetup();
        $other = MedicalFixtures::subject();

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0'), ['values' => [
            'source' => 'manual', 'verified_at' => now()->toDateTimeString(), 'subject_id' => $other->id, 'verified_by' => $user->id, 'name' => 'Edited name',
        ]])->assertOk()->assertJson(['edited' => true]);

        $row = DB::table('medical_medications')->sole();
        expect($row->source)->toBe('ai')->and($row->verified_at)->toBeNull()->and($row->subject_id)->toBe($person->id)->and($row->name)->toBe('Edited name');
    });

    it('still works with consent switched off', function () {
        [$user, $person, , $extraction] = acceptSetup(consent: false);

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0'), [])->assertOk()->assertJson(['state' => 'accepted']);
    });

    it('writes nothing when the audit write fails', function () {
        [$user, $person, , $extraction] = acceptSetup();
        app()->instance(AuditLogger::class, new FailingAuditLogger);
        $before = acceptCounts();

        expect(fn () => app(AiReviewQuery::class)->accept(Viewer::for($user), $person, $extraction->id, 'visit-0', null, $user))->toThrow(RuntimeException::class);

        expect(acceptCounts())->toBe($before)->and(DB::table('medical_documents')->whereNotNull('visit_id')->count())->toBe(0);
    });
});

describe('reject and undo', function () {
    it('marks only the entry rejected, restores it on undo and leaves the records alone', function () {
        [$user, $person, $document, $extraction] = acceptSetup();
        $before = acceptCounts();

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit-1', 'reject'), [])
            ->assertOk()->assertExactJson(['state' => 'rejected', 'pending' => 6]);
        expect(DB::table('medical_ai_decisions')->sole()->decision)->toBe('rejected')
            ->and(DB::table('medical_visits')->count())->toBe($before['visits']);
        expect(AuditEntry::where('event', 'medical_ai_entry_decided')->sole()->meta)->toEqual(['subject' => $person->id, 'document' => $document->id, 'decision' => 'rejected']);

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit-1', 'reject'), [])->assertOk()->assertJson(['state' => 'rejected']);
        expect(DB::table('medical_ai_decisions')->count())->toBe(1);

        $this->actingAs($user)->deleteJson(acceptUrl($person, $extraction->id, 'visit-1', 'reject'))
            ->assertOk()->assertExactJson(['state' => 'pending', 'pending' => 7]);
        expect(DB::table('medical_ai_decisions')->count())->toBe(0);
        expect(AuditEntry::where('event', 'medical_ai_entry_decided')->latest('id')->first()->meta['decision'])->toBe('undone');
    });

    it('lets a rejected entry be accepted after undo, and keeps an accepted entry on undo', function () {
        [$user, $person, , $extraction] = acceptSetup();
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0', 'reject'), [])->assertOk();
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0'), [])->assertOk()->assertJson(['state' => 'rejected']);

        $this->actingAs($user)->deleteJson(acceptUrl($person, $extraction->id, 'medication-0', 'reject'))->assertOk();
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0'), [])->assertOk()->assertJson(['state' => 'accepted']);

        $audits = AuditEntry::where('event', 'medical_ai_entry_decided')->count();
        $this->actingAs($user)->deleteJson(acceptUrl($person, $extraction->id, 'medication-0', 'reject'))->assertOk()->assertJson(['state' => 'accepted']);
        expect(DB::table('medical_ai_decisions')->sole()->decision)->toBe('accepted')
            ->and(DB::table('medical_medications')->count())->toBe(1)
            ->and(AuditEntry::where('event', 'medical_ai_entry_decided')->count())->toBe($audits);
    });
});

describe('guards', function () {
    it('answers 409 superseded and writes nothing on a superseded proposal', function (string $method, string $action) {
        [$user, $person, , $extraction] = acceptSetup(state: 'superseded');
        $before = acceptCounts();

        $this->actingAs($user)->json($method, acceptUrl($person, $extraction->id, 'medication-0', $action), [])
            ->assertStatus(409)->assertExactJson(['state' => 'superseded']);

        expect(acceptCounts())->toBe($before);
    })->with([['POST', 'accept'], ['POST', 'reject'], ['DELETE', 'reject']]);

    it('answers 403 on a queued or failed extraction', function (string $state) {
        [$user, $person, , $extraction] = acceptSetup(state: $state);

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0'), [])->assertForbidden();
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0', 'reject'), [])->assertForbidden();
        expect(acceptCounts()['decisions'])->toBe(0);
    })->with(['queued', 'failed']);

    it('answers one identical 403 for hidden, view-only, unknown and malformed ids and entries', function () {
        [$user, $person, , $extraction] = acceptSetup();
        [$viewer, $viewPerson, , $viewExtraction] = acceptSetup(AccessLevel::View);
        [, $hiddenPerson, , $hiddenExtraction] = acceptSetup();
        $before = acceptCounts();

        $shapes = [
            acceptShape($this->actingAs($viewer)->postJson(acceptUrl($viewPerson, $viewExtraction->id, 'visit-0'), [])),
            acceptShape($this->actingAs($user)->postJson(acceptUrl($hiddenPerson, $hiddenExtraction->id, 'visit-0'), [])),
            acceptShape($this->actingAs($user)->postJson(acceptUrl($person, $hiddenExtraction->id, 'visit-0'), [])),
            acceptShape($this->actingAs($user)->postJson(acceptUrl($person, (string) Str::uuid(), 'visit-0'), [])),
            acceptShape($this->actingAs($user)->postJson(acceptUrl($person, 'abc', 'visit-0'), [])),
            acceptShape($this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'lab-0'), [])),
            acceptShape($this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit-99'), [])),
            acceptShape($this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit:0'), [])),
            acceptShape($this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit-07'), [])),
        ];

        expect(array_unique($shapes))->toHaveCount(1)->and($shapes[0])->toStartWith('403|')
            ->and(acceptCounts())->toBe($before);
    });

    it('does not let a view member decide, even with module Edit', function () {
        [$user, $person, , $extraction] = acceptSetup(AccessLevel::View);

        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'visit-0', 'reject'), [])->assertForbidden();
        $this->actingAs($user)->deleteJson(acceptUrl($person, $extraction->id, 'visit-0', 'reject'))->assertForbidden();
        expect(DB::table('medical_ai_decisions')->count())->toBe(0);
    });
});

describe('re-runs and limits', function () {
    /** A second ready proposal for the same document, as after a re-run (the old one superseded). */
    function acceptRerun(MedicalDocument $document, User $user): MedicalAiExtraction
    {
        DB::table('medical_ai_extractions')->where('document_id', $document->id)->where('status', 'ready')
            ->update(['status' => 'superseded']);

        return MedicalAiExtraction::factory()->forDocument($document)->ready(acceptPayload(), 7)->create(['started_by' => $user->id]);
    }

    it('links an entry of a re-run to the record the first run created instead of inserting it again', function () {
        [$user, $person, $document, $first] = acceptSetup();
        $one = $this->actingAs($user)->postJson(acceptUrl($person, $first->id, 'medication-0'), [])->assertOk()->json();
        $this->actingAs($user)->postJson(acceptUrl($person, $first->id, 'allergy-1'), ['values' => ['severity' => 'mild']])->assertOk();
        $this->actingAs($user)->postJson(acceptUrl($person, $first->id, 'visit-0'), [])->assertOk();
        $this->actingAs($user)->postJson(acceptUrl($person, $first->id, 'vaccination-0'), ['values' => ['given_on' => '2025-01-10', 'next_due_on' => '2035-01-10']])->assertOk();
        $before = [
            DB::table('medical_medications')->count(), DB::table('medical_allergies')->count(), DB::table('medical_visits')->count(),
            DB::table('medical_vaccinations')->count(), DB::table('notification_outbox')->count(),
        ];

        $second = acceptRerun($document, $user);
        $two = $this->actingAs($user)->postJson(acceptUrl($person, $second->id, 'medication-0'), [])->assertOk()->json();
        $this->actingAs($user)->postJson(acceptUrl($person, $second->id, 'allergy-1'), ['values' => ['severity' => 'mild']])->assertOk();
        $this->actingAs($user)->postJson(acceptUrl($person, $second->id, 'visit-0'), [])->assertOk();
        $this->actingAs($user)->postJson(acceptUrl($person, $second->id, 'vaccination-0'), ['values' => ['given_on' => '2025-01-10', 'next_due_on' => '2035-01-10']])->assertOk();

        expect($two['state'])->toBe('accepted')->and($two['recordId'])->toBe($one['recordId'])
            ->and([
                DB::table('medical_medications')->count(), DB::table('medical_allergies')->count(), DB::table('medical_visits')->count(),
                DB::table('medical_vaccinations')->count(), DB::table('notification_outbox')->count(),
            ])->toBe($before)
            ->and(DB::table('medical_ai_decisions')->where('extraction_id', $second->id)->count())->toBe(4);
    });

    it('also treats a manually entered record with the same content as already present, but not a different one', function () {
        [$user, $person, $document, $extraction] = acceptSetup();
        $manual = (string) Str::uuid();
        DB::table('medical_medications')->insert([
            'id' => $manual, 'subject_id' => $person->id, 'name' => ' IBUPROFEN ', 'dose' => '200 mg', 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $same = $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0'), [])->assertOk()->json();
        expect($same['recordId'])->toBe($manual)->and(DB::table('medical_medications')->count())->toBe(1);

        $other = acceptRerun($document, $user);
        $edited = $this->actingAs($user)->postJson(acceptUrl($person, $other->id, 'medication-0'), ['values' => ['dose' => '400 mg']])->assertOk()->json();
        expect($edited['recordId'])->not->toBe($manual)->and(DB::table('medical_medications')->count())->toBe(2);
    });

    it('refuses to create more records than the proposal cap or the person cap allows, and still links duplicates', function () {
        [$user, $person, , $extraction] = acceptSetup();
        config(['ai.max_accepts_per_proposal' => 1]);
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'medication-0'), [])->assertOk();
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'allergy-1'), ['values' => ['severity' => 'mild']])
            ->assertStatus(422)->assertJsonValidationErrors(['record_limit']);
        expect(DB::table('medical_allergies')->count())->toBe(0)->and(DB::table('medical_ai_decisions')->count())->toBe(1);

        config(['ai.max_accepts_per_proposal' => 100, 'medical.max_records_per_subject' => 1]);
        $this->actingAs($user)->postJson(acceptUrl($person, $extraction->id, 'allergy-1'), ['values' => ['severity' => 'mild']])
            ->assertStatus(422)->assertJsonValidationErrors(['record_limit']);
        expect(DB::table('medical_allergies')->count())->toBe(0);
    });
});
